<?php

namespace App\Http\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/** Reasignación contable, sin llamadas al PAC ni movimientos de series físicas. */
class RefacturacionService
{
    const TABLE = 'documento_refacturacion';

    public function preview($documentId)
    {
        $this->requireSchema();
        $document = $this->document($documentId);
        $existing = DB::table(self::TABLE)->where('id_documento_original', $documentId)->first();
        $blockers = [];
        try {
            $this->eligible($document);
            $this->applications($document);
            $this->creditNoteEntity($document, 0, '', true);
        } catch (InvalidArgumentException $e) {
            $blockers[] = $e->getMessage();
        }
        $entity = DB::table('documento_entidad')->where('id', $document->id_entidad)->first();
        return [
            'documento' => (int) $document->id,
            'total' => $document->total,
            'cliente_actual' => $entity ? [
                'razon_social' => $entity->razon_social, 'rfc' => $entity->rfc,
                'codigo_postal_fiscal' => $entity->codigo_postal_fiscal,
            ] : null,
            'receptor_cfdi_original' => $this->originalInvoiceReceiver($document, $entity),
            'requiere_token' => $this->requiresToken($document),
            'puede_refacturar' => !$existing && empty($blockers),
            'bloqueos' => $blockers,
            'resultado' => $existing ? $this->result($existing, true) : null,
            'regimenes' => DB::table('cat_regimen')->orderBy('codigo')->get(['codigo', 'regimen', 'condicion']),
            'usos_cfdi' => DB::table('documento_uso_cfdi')->whereNotIn('codigo', ['P01', 'CP01'])->orderBy('codigo')->get(['id', 'codigo', 'descripcion']),
        ];
    }

    public function create($documentId, array $recipient, $userId, callable $authorizePreviousMonth)
    {
        $this->requireSchema();
        $recipient = $this->recipient($recipient);
        $hash = hash('sha256', json_encode($recipient));

        return DB::transaction(function () use ($documentId, $recipient, $userId, $hash, $authorizePreviousMonth) {
            // Serializa dos clics/reintentos sobre el mismo pedido antes de crear cualquier fila.
            $source = $this->document($documentId, true);
            $existing = DB::table(self::TABLE)->where('id_documento_original', $source->id)->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw new InvalidArgumentException('La venta ya fue refacturada con otros datos. Consulta su pedido nuevo; no se creó otra operación.');
                }
                return $this->result($existing, true);
            }
            $this->eligible($source);
            $applications = $this->applications($source, true);
            if ($this->requiresToken($source)) {
                $authorizePreviousMonth();
            }
            $lines = DB::table('movimiento')->where('id_documento', $source->id)->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw new InvalidArgumentException('La venta no contiene productos para refacturar.');
            }
            $ncUse = DB::table('documento_uso_cfdi')->where('codigo', 'G02')->value('id');
            $compensation = DB::table('cat_forma_pago')->where('codigo_sat', '17')->value('id');
            if (!$ncUse || !$compensation) {
                throw new InvalidArgumentException('Falta configurar G02 o la forma contable de compensación (17).');
            }

            $now = Carbon::now()->format('Y-m-d H:i:s');
            $entityId = DB::table('documento_entidad')->insertGetId([
                'id_erp' => '0', 'tipo' => 1, 'razon_social' => $recipient['razon_social'],
                'rfc' => $recipient['rfc'], 'codigo_postal_fiscal' => $recipient['codigo_postal_fiscal'],
                'regimen_id' => $recipient['regimen'], 'regimen' => $recipient['regimen'],
                'regimen_letra' => $recipient['regimen_descripcion'], 'pais' => '412',
                'correo' => $recipient['correo'], 'telefono' => $recipient['telefono'],
                'info_extra' => json_encode(['refacturacion_origen' => (int) $source->id]),
                'status' => 1, 'created_by_user' => $userId, 'updated_by_user' => $userId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $noteEntity = $this->creditNoteEntity($source, $userId, $now);
            $noteId = $this->copyDocument($source, 6, $noteEntity, $ncUse, $userId, $now);
            $newId = $this->copyDocument($source, 2, $entityId, $recipient['id_cfdi'], $userId, $now);
            foreach ($lines as $line) {
                // La pareja NC(+)/venta(-) es neutra en existencia. No clonar movimiento_producto.
                $copy = array_intersect_key((array) $line, array_flip([
                    'id_modelo', 'cantidad', 'precio', 'descuento', 'garantia', 'regalo', 'retencion', 'addenda',
                ]));
                $copy += ['modificacion' => '', 'comentario' => 'Refacturación de movimiento ' . $line->id,
                    'created_at' => $now, 'updated_at' => $now];
                foreach ([$noteId, $newId] as $id) {
                    DB::table('movimiento')->insert($copy + ['id_documento' => $id]);
                }
            }

            $transfers = [];
            $remaining = $this->units($source->total);
            foreach ($applications as $application) {
                $remaining -= $this->units($application->monto_aplicado);
                $previousOrigin = DB::table('movimiento_contable')
                    ->where('id', $application->id_movimiento_contable)->first();
                DB::table('movimiento_contable_documento')->where('id', $application->id)->update(['status' => 0]);
                DB::table('movimiento_contable')->where('id', $application->id_movimiento_contable)->update([
                    'entidad_origen' => $entityId,
                    'nombre_entidad_origen' => $recipient['razon_social'],
                    'updated_at' => $now,
                ]);
                $newApplication = DB::table('movimiento_contable_documento')->insertGetId([
                    'id_movimiento_contable' => $application->id_movimiento_contable, 'id_documento' => $newId,
                    'monto_aplicado' => $application->monto_aplicado, 'saldo_documento' => $this->decimal($remaining),
                    'moneda' => $application->moneda, 'tipo_cambio' => $application->tipo_cambio,
                    'parcialidad' => $application->parcialidad, 'created_at' => $now, 'status' => 1,
                ]);
                $transfers[] = ['anterior' => $application->id, 'nueva' => $newApplication,
                    'ingreso' => $application->id_movimiento_contable, 'monto' => $application->monto_aplicado,
                    'nombre_origen_anterior' => $previousOrigin->nombre_entidad_origen];
            }
            // Una NC contable, no un egreso de banco ni un nuevo ingreso.
            $noteMovement = DB::table('movimiento_contable')->insertGetId([
                'folio' => 'NC-' . $noteId, 'id_tipo_afectacion' => 4,
                'fecha_operacion' => $now, 'fecha_afectacion' => $now,
                'id_moneda' => $source->id_moneda, 'tipo_cambio' => $source->tipo_cambio, 'monto' => $source->total,
                'origen_tipo' => 1, 'entidad_origen' => $noteEntity,
                'destino_tipo' => 1, 'entidad_destino' => $noteEntity,
                'id_forma_pago' => $compensation, 'referencia_pago' => 'NC-' . $noteId,
                'descripcion_pago' => 'Refacturación de pedido ' . $source->id . ' a ' . $newId,
                'comentarios' => 'Compensación contable sin salida de efectivo; timbrado pendiente.',
                'creado_por' => $userId, 'status' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('movimiento_contable_documento')->insert([
                'id_movimiento_contable' => $noteMovement, 'id_documento' => $source->id,
                'monto_aplicado' => $source->total, 'saldo_documento' => '0.0000', 'moneda' => $source->id_moneda,
                'tipo_cambio' => $source->tipo_cambio, 'parcialidad' => 1, 'created_at' => $now, 'status' => 1,
            ]);
            DB::table('documento')->where('id', $source->id)->update([
                'refacturado' => 1, 'refacturado_at' => $now, 'nota' => (string) $noteId,
                'pagado' => 1, 'saldo' => '0.0000', 'updated_at' => $now,
            ]);
            $id = DB::table(self::TABLE)->insertGetId([
                'id_documento_original' => $source->id, 'id_documento_nuevo' => $newId,
                'id_nota_credito' => $noteId, 'id_entidad_nueva' => $entityId,
                'id_movimiento_nota' => $noteMovement, 'created_by' => $userId,
                'estado_fiscal' => 'pendiente_timbrado', 'request_hash' => $hash,
                'auditoria' => json_encode(['uuid_original' => $source->uuid, 'total' => $source->total,
                    'entidad_original' => $source->id_entidad, 'entidad_nota' => $noteEntity,
                    'receptor_nuevo' => $recipient,
                    'aplicaciones' => $transfers], JSON_UNESCAPED_UNICODE),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return $this->result(DB::table(self::TABLE)->where('id', $id)->first(), false);
        }, 3);
    }

    public function previewClient($documentId)
    {
        $document = $this->document($documentId);
        $blockers = [];
        try { $this->assertClientEditable($document); } catch (InvalidArgumentException $e) { $blockers[] = $e->getMessage(); }
        $entity = DB::table('documento_entidad')->where('id', $document->id_entidad)->first();
        return [
            'documento' => (int) $document->id, 'total' => $document->total,
            'puede_refacturar' => empty($blockers), 'bloqueos' => $blockers,
            'cliente_actual' => $entity, 'requiere_token' => false, 'resultado' => null,
            'receptor' => $entity ? [
                'rfc' => $entity->rfc, 'razon_social' => $entity->razon_social,
                'codigo_postal_fiscal' => $entity->codigo_postal_fiscal,
                'regimen' => $entity->regimen, 'id_cfdi' => $document->id_cfdi,
                'correo' => $entity->correo, 'telefono' => $entity->telefono,
            ] : null,
            'regimenes' => DB::table('cat_regimen')->orderBy('codigo')->get(['codigo', 'regimen', 'condicion']),
            'usos_cfdi' => DB::table('documento_uso_cfdi')->whereNotIn('codigo', ['P01', 'CP01'])->orderBy('codigo')->get(['id', 'codigo', 'descripcion']),
        ];
    }

    public function updateClient($documentId, array $input, $userId)
    {
        $recipient = $this->recipient($input);
        return DB::transaction(function () use ($documentId, $recipient, $userId) {
            $document = $this->document($documentId, true);
            $this->assertClientEditable($document);
            $entity = DB::table('documento_entidad')->where('id', $document->id_entidad)->first();
            $hasPayments = DB::table('movimiento_contable_documento')->where('id_documento', $documentId)->where('status', 1)->exists();
            $applications = $hasPayments ? $this->applications($document, true, false) : collect();
            $now = date('Y-m-d H:i:s');
            // Una entidad propia evita cambiar el catálogo compartido por otros pedidos.
            $newEntity = array_intersect_key((array) $entity, array_flip([
                'limite', 'condicion', 'telefono_alt', 'direccion',
            ]));
            $newEntity += [
                'id_erp' => '0', 'tipo' => 1, 'razon_social' => $recipient['razon_social'],
                'rfc' => $recipient['rfc'], 'codigo_postal_fiscal' => $recipient['codigo_postal_fiscal'],
                'regimen_id' => $recipient['regimen'], 'regimen' => $recipient['regimen'],
                'regimen_letra' => $recipient['regimen_descripcion'], 'pais' => '412',
                'correo' => $recipient['correo'], 'telefono' => $recipient['telefono'],
                'info_extra' => json_encode(['receptor_fiscal_documento' => (int) $documentId]),
                'status' => 1, 'created_by_user' => $userId, 'updated_by_user' => $userId,
                'created_at' => $now, 'updated_at' => $now,
            ];
            $entityId = DB::table('documento_entidad')->insertGetId($newEntity);
            foreach ($applications as $application) {
                DB::table('movimiento_contable')->where('id', $application->id_movimiento_contable)->update([
                    'entidad_origen' => $entityId, 'nombre_entidad_origen' => $recipient['razon_social'], 'updated_at' => $now,
                ]);
            }
            DB::table('documento')->where('id', $documentId)->update([
                'id_entidad' => $entityId, 'id_cfdi' => $recipient['id_cfdi'], 'updated_at' => $now,
            ]);
            DB::table('seguimiento')->insert([
                'id_documento' => $documentId, 'id_usuario' => $userId,
                'seguimiento' => 'Cliente fiscal actualizado antes de timbrar. Entidad anterior: ' . $document->id_entidad
                    . '; entidad nueva: ' . $entityId . '. Sin cambio de fase ni movimientos de inventario.',
            ]);
            return ['documento_original' => (int) $documentId, 'cliente_actualizado' => true,
                'entidad_nueva' => $entityId, 'receptor' => $recipient];
        }, 3);
    }

    private function assertClientEditable($document)
    {
        if ((int) $document->id_tipo !== 2 || (int) $document->status !== 1 || $document->deleted_at
            || (int) $document->id_fase !== 5 || preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', trim((string) $document->uuid))) {
            throw new InvalidArgumentException('Sólo puedes editar el cliente de una venta activa en fase 5 sin timbrar. Una venta timbrada requiere refacturación.');
        }
        $active = DB::table('facturacion_solicitud as fs')
            ->join('facturacion_solicitud_documento as link', 'link.id_solicitud', '=', 'fs.id')
            ->where('link.id_documento', $document->id)
            ->whereNotIn('fs.status', ['rejected', 'cancelled'])->exists();
        if ($active) {
            throw new InvalidArgumentException('La venta ya tiene una solicitud fiscal activa. Actualiza su estado antes de cambiar el cliente.');
        }
    }

    public static function hasExplicitRecipient($documentId)
    {
        $entity = DB::table('documento as d')->join('documento_entidad as de', 'de.id', '=', 'd.id_entidad')
            ->where('d.id', $documentId)->select('de.*')->first();
        $extra = $entity ? json_decode((string) ($entity->info_extra ?? ''), true) : null;
        return (int) ($extra['receptor_fiscal_documento'] ?? 0) === (int) $documentId;
    }

    public static function fiscalBlocks(array $ids, $global = false)
    {
        if (!$ids || !Schema::hasTable(self::TABLE)) {
            return [];
        }
        $rows = DB::table(self::TABLE)->where(function ($query) use ($ids) {
            $query->whereIn('id_documento_nuevo', $ids)->orWhereIn('id_nota_credito', $ids);
        })->get();
        $blocks = [];
        foreach ($rows as $row) {
            if ($global) {
                foreach ([$row->id_documento_nuevo, $row->id_nota_credito] as $id) {
                    $blocks[$id] = 'Los documentos de refacturación sólo se timbran individualmente.';
                }
            }
        }
        return $blocks;
    }

    public static function assertFiscalReady(array $ids, $global = false)
    {
        $blocks = self::fiscalBlocks($ids, $global);
        if ($blocks) {
            throw new InvalidArgumentException(reset($blocks));
        }
    }

    public static function isReplacementSale($documentId)
    {
        return Schema::hasTable(self::TABLE)
            && DB::table(self::TABLE)->where('id_documento_nuevo', (int) $documentId)->exists();
    }

    public static function isRefacturationCreditNote($documentId)
    {
        return Schema::hasTable(self::TABLE)
            && DB::table(self::TABLE)->where('id_nota_credito', (int) $documentId)->exists();
    }

    public static function markFiscalCompleted(array $documentIds)
    {
        if (!$documentIds || !Schema::hasTable(self::TABLE)) {
            return;
        }
        $operations = DB::table(self::TABLE)->where(function ($query) use ($documentIds) {
            $query->whereIn('id_documento_nuevo', $documentIds)
                ->orWhereIn('id_nota_credito', $documentIds);
        })->get();
        foreach ($operations as $operation) {
            $ready = DB::table('documento as d')
                ->join('documento_factura as df', 'df.id_documento', '=', 'd.id')
                ->whereIn('d.id', [(int) $operation->id_documento_nuevo, (int) $operation->id_nota_credito])
                ->where('d.id_fase', 6)->where('d.status', 1)
                ->whereRaw("TRIM(COALESCE(df.xml, '')) <> ''")->whereRaw("TRIM(COALESCE(df.pdf, '')) <> ''")
                ->get(['d.uuid']);
            if ($ready->count() === 2 && $ready->every(function ($row) {
                return preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', (string) $row->uuid);
            })) {
                DB::table(self::TABLE)->where('id', $operation->id)->update([
                    'estado_fiscal' => 'timbradas', 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    private function requireSchema()
    {
        if (!Schema::hasTable(self::TABLE)) {
            throw new InvalidArgumentException('Refacturación no está habilitada: falta aplicar su migración de base de datos.');
        }
    }

    private function document($id, $lock = false)
    {
        $query = DB::table('documento')->where('id', (int) $id);
        $document = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$document) {
            throw new InvalidArgumentException('No se encontró la venta.');
        }
        return $document;
    }

    private function eligible($document)
    {
        if ((int) $document->id_tipo !== 2 || (int) $document->status !== 1 || $document->deleted_at
            || (int) $document->id_fase !== 6
            || !preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', trim($document->uuid))) {
            throw new InvalidArgumentException('Sólo se puede refacturar una venta activa, terminada y con UUID timbrado válido.');
        }
        if ($document->refacturado || !in_array(strtoupper(trim((string) $document->nota)), ['', 'N/A', '0'], true)) {
            throw new InvalidArgumentException('La venta ya tiene una refacturación o nota de crédito; requiere revisión contable.');
        }
        if (!$document->id_entidad || $this->units($document->total) <= 0) {
            throw new InvalidArgumentException('La venta no tiene entidad o total válido.');
        }
        if (DB::table(self::TABLE)->where('id_documento_nuevo', $document->id)->exists()) {
            throw new InvalidArgumentException('Este pedido proviene de una refacturación; requiere revisión contable antes de repetirla.');
        }
    }

    private function applications($document, $lock = false, $requireSettled = true)
    {
        $query = DB::table('movimiento_contable_documento')->where('id_documento', $document->id)
            ->where('status', 1)->orderBy('id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        $heads = DB::table('movimiento_contable')->whereIn('id', $rows->pluck('id_movimiento_contable')->all())->orderBy('id');
        $heads = ($lock ? $heads->lockForUpdate() : $heads)->get()->keyBy('id');
        $sum = 0;
        foreach ($rows as $row) {
            $head = $heads->get($row->id_movimiento_contable);
            if (!$head || (int) $head->status !== 1 || (int) $head->id_tipo_afectacion !== 1
                || (int) $head->origen_tipo !== 1 || (int) $head->entidad_origen !== (int) $document->id_entidad
                || (int) $row->moneda !== (int) $document->id_moneda
                || (int) $head->id_moneda !== (int) $document->id_moneda
                || $this->units($row->monto_aplicado) <= 0) {
                throw new InvalidArgumentException('La venta tiene aplicaciones distintas de ingresos activos en su moneda. Requiere conciliación antes de refacturar.');
            }
            $sum += $this->units($row->monto_aplicado);
        }
        foreach ($heads as $head) {
            $active = DB::table('movimiento_contable_documento')->where('id_movimiento_contable', $head->id)
                ->where('status', 1)->get();
            $allocated = 0;
            foreach ($active as $allocation) {
                if ((int) $allocation->id_documento !== (int) $document->id) {
                    throw new InvalidArgumentException('Un ingreso también está aplicado a otro pedido. Sepáralo antes de cambiar su cliente.');
                }
                $allocated += $this->units($allocation->monto_aplicado);
            }
            if ($allocated !== $this->units($head->monto)) {
                throw new InvalidArgumentException('Un ingreso tiene importe no aplicado o inconsistente. Concílialo antes de cambiar su cliente.');
            }
        }
        if ($sum > $this->units($document->total) || ($requireSettled && $sum !== $this->units($document->total))) {
            throw new InvalidArgumentException('La venta debe estar totalmente saldada con ingresos activos, sin faltantes ni excedentes.');
        }
        return $rows;
    }

    private function requiresToken($document)
    {
        return substr((string) $document->created_at, 0, 7)
            !== Carbon::now(config('nexfira.fiscal_timezone', 'America/Mexico_City'))->format('Y-m');
    }

    private function creditNoteEntity($source, $userId, $now, $preview = false)
    {
        $original = DB::table('documento_entidad')->where('id', $source->id_entidad)->first();
        if (!$original) {
            throw new InvalidArgumentException('No se encontró la entidad fiscal de la factura original.');
        }
        $payload = DB::table('facturacion_solicitud as fs')
            ->join('facturacion_solicitud_documento as link', 'link.id_solicitud', '=', 'fs.id')
            ->where('link.id_documento', $source->id)->where('fs.status', 'stamped')
            ->whereRaw('UPPER(fs.fiscal_uuid) = ?', [strtoupper((string) $source->uuid)])
            ->orderBy('fs.id', 'desc')
            ->value('fs.request_payload');
        $stored = $payload ? json_decode($payload, true) : null;
        $invoiceRfc = strtoupper(trim((string) ($stored['content']['receiver']['rfc'] ?? '')));
        if ($invoiceRfc === '') {
            $publico = DB::table('marketplace_area')->where('id', $source->id_marketplace_area)->value('publico');
            if ((int) $publico === 1 && strtoupper(trim((string) $original->rfc)) !== 'XAXX010101000') {
                throw new InvalidArgumentException('No se pudo verificar el receptor de la factura original. Este marketplace factura a público general, pero la entidad del pedido es otra. Se requiere el XML original antes de crear la NC.');
            }
            $invoiceRfc = strtoupper(trim((string) $original->rfc));
        }
        if ($invoiceRfc !== 'XAXX010101000') {
            if ($invoiceRfc !== strtoupper(trim((string) $original->rfc))) {
                throw new InvalidArgumentException('El receptor de la factura original no coincide con su entidad; requiere revisión fiscal.');
            }
            return (int) $source->id_entidad;
        }
        if (strtoupper(trim((string) $original->rfc)) === 'XAXX010101000') {
            return (int) $source->id_entidad;
        }
        if ($preview) {
            return 0;
        }
        $receiver = $stored['content']['receiver'] ?? [];
        return DB::table('documento_entidad')->insertGetId([
            'id_erp' => '0', 'tipo' => 1, 'razon_social' => 'PUBLICO EN GENERAL',
            'rfc' => 'XAXX010101000', 'codigo_postal_fiscal' => $receiver['postalCode'] ?? config('nexfira.global.receiver_postal_code'),
            'regimen_id' => '616', 'regimen' => '616', 'regimen_letra' => 'Sin obligaciones fiscales',
            'pais' => '412', 'correo' => '', 'telefono' => '',
            'info_extra' => json_encode(['refacturacion_origen' => (int) $source->id, 'receptor_cfdi_original' => true]),
            'status' => 1, 'created_by_user' => $userId, 'updated_by_user' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function originalInvoiceReceiver($source, $entity)
    {
        $payload = DB::table('facturacion_solicitud as fs')
            ->join('facturacion_solicitud_documento as link', 'link.id_solicitud', '=', 'fs.id')
            ->where('link.id_documento', $source->id)->where('fs.status', 'stamped')
            ->whereRaw('UPPER(fs.fiscal_uuid) = ?', [strtoupper((string) $source->uuid)])
            ->orderBy('fs.id', 'desc')->value('fs.request_payload');
        $stored = $payload ? json_decode($payload, true) : null;
        $receiver = $stored['content']['receiver'] ?? null;
        if (is_array($receiver) && isset($receiver['rfc'])) {
            return ['rfc' => $receiver['rfc'], 'nombre' => $receiver['name'] ?? '', 'verificado' => true];
        }
        return $entity ? ['rfc' => $entity->rfc, 'nombre' => $entity->razon_social, 'verificado' => false] : null;
    }

    private function recipient(array $input)
    {
        $rfc = mb_strtoupper(trim((string) ($input['rfc'] ?? '')), 'UTF-8');
        $name = mb_strtoupper(trim((string) ($input['razon_social'] ?? '')), 'UTF-8');
        $postal = trim((string) ($input['codigo_postal_fiscal'] ?? ''));
        if (!preg_match('/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/u', $rfc)
            || in_array($rfc, ['XAXX010101000', 'XEXX010101000'], true)) {
            throw new InvalidArgumentException('Captura el RFC específico del nuevo cliente, no el genérico de público general.');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150 || !preg_match('/^[0-9]{5}$/', $postal) || $postal === '00000') {
            throw new InvalidArgumentException('Captura nombre fiscal (2 a 150 caracteres) y código postal fiscal de cinco dígitos.');
        }
        $regime = DB::table('cat_regimen')->where('codigo', (string) ($input['regimen'] ?? ''))->first();
        $use = DB::table('documento_uso_cfdi')->where('id', (int) ($input['id_cfdi'] ?? 0))->first();
        if (!$regime || !$use || in_array($use->codigo, ['P01', 'CP01'], true)) {
            throw new InvalidArgumentException('Selecciona un régimen fiscal y uso CFDI vigentes del catálogo.');
        }
        if (strpos((string) $regime->condicion, mb_strlen($rfc) === 12 ? 'M' : 'F') === false) {
            throw new InvalidArgumentException('El régimen seleccionado no corresponde al tipo de persona del RFC.');
        }
        $email = trim((string) ($input['correo'] ?? ''));
        $phone = trim((string) ($input['telefono'] ?? ''));
        if (($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200)) || mb_strlen($phone) > 50) {
            throw new InvalidArgumentException('Revisa el correo y teléfono del cliente.');
        }
        return ['rfc' => $rfc, 'razon_social' => $name, 'codigo_postal_fiscal' => $postal,
            'regimen' => (string) $regime->codigo, 'regimen_descripcion' => $regime->regimen,
            'id_cfdi' => (int) $use->id, 'correo' => $email, 'telefono' => $phone];
    }

    private function copyDocument($source, $type, $entityId, $cfdi, $userId, $now)
    {
        $fields = array_intersect_key((array) $source, array_flip([
            'id_almacen_principal_empresa', 'id_almacen_secundario_empresa', 'id_marketplace_area',
            'id_moneda', 'tipo_cambio', 'id_periodo', 'id_paqueteria', 'fulfillment', 'total', 'sandbox',
        ]));
        return DB::table('documento')->insertGetId($fields + [
            'id_tipo' => $type, 'id_entidad' => $entityId, 'id_cfdi' => $cfdi, 'id_usuario' => $userId,
            'id_fase' => $type === 2 ? 5 : 6, 'status' => 1, 'saldo' => '0.0000', 'pagado' => 1,
            'es_refactura' => 1,
            'nota' => 'N/A', 'uuid' => 'N/A', 'factura_serie' => '', 'factura_folio' => '',
            'no_venta' => ($type === 6 ? 'NC-REF-' : 'REF-') . $source->id, 'refacturado' => 0,
            'referencia' => 'Refacturación del pedido interno ' . $source->id . '; venta MP: ' . $source->no_venta,
            'observacion' => 'Operación contable sin nueva entrega ni devolución física. Timbrado individual pendiente.',
            'comentario' => '', 'info_extra' => json_encode(['refacturacion_origen' => (int) $source->id]),
            'finished_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function result($row, $reused)
    {
        return ['id' => (int) $row->id, 'documento_original' => (int) $row->id_documento_original,
            'documento_nuevo' => (int) $row->id_documento_nuevo, 'nota_credito' => (int) $row->id_nota_credito,
            'entidad_nueva' => (int) $row->id_entidad_nueva, 'estado_fiscal' => $row->estado_fiscal, 'reutilizada' => $reused];
    }

    private function units($value)
    {
        // Escala monetaria de las aplicaciones: 4 decimales, sin aritmética binaria de dinero.
        $value = (string) $value;
        if (!preg_match('/^([0-9]{1,12})(?:\.([0-9]{1,4}))?$/', $value, $parts)) {
            throw new InvalidArgumentException('Importe contable ausente o fuera del rango admitido.');
        }
        return (int) $parts[1] * 10000 + (int) str_pad($parts[2] ?? '', 4, '0');
    }

    private function decimal($units)
    {
        return intdiv($units, 10000) . '.' . str_pad((string) ($units % 10000), 4, '0', STR_PAD_LEFT);
    }
}
