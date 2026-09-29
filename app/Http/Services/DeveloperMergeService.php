<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class DeveloperMergeService
{
    private const FIELDS = [
        'entidades' => ['id_erp', 'regimen_id', 'razon_social', 'rfc', 'telefono', 'telefono_alt', 'correo',
            'info_extra', 'limite', 'condicion', 'regimen', 'pais', 'codigo_postal_fiscal', 'regimen_letra'],
        'productos' => ['id_tipo', 'sku', 'np', 'descripcion', 'costo', 'costo_extra', 'alto', 'ancho',
            'largo', 'peso', 'serie', 'refurbished', 'clave_sat', 'unidad', 'clave_unidad',
            'consecutivo', 'cat1', 'cat2', 'cat3', 'cat4', 'caducidad'],
    ];

    private function table($kind)
    {
        if ($kind === 'entidades') { return 'documento_entidad'; }
        if ($kind === 'productos') { return 'modelo'; }
        throw new InvalidArgumentException('Selecciona entidades o productos.');
    }

    private function id($value)
    {
        if (!ctype_digit((string) $value) || (int) $value < 1) {
            throw new InvalidArgumentException('Selecciona dos IDs válidos.');
        }
        return (int) $value;
    }

    public function search($kind, $query)
    {
        $table = $this->table($kind);
        $query = trim((string) $query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            throw new InvalidArgumentException('Escribe al menos dos caracteres para buscar.');
        }
        $q = DB::table($table)->where('status', 1)->whereNull('deleted_at');
        $q->where(function ($builder) use ($kind, $query) {
            if (ctype_digit($query)) { $builder->orWhere('id', (int) $query); }
            if ($kind === 'entidades') {
                $builder->orWhere('razon_social', 'like', '%' . $query . '%')->orWhere('rfc', 'like', '%' . $query . '%');
            } else {
                $builder->orWhere('sku', 'like', '%' . $query . '%')->orWhere('descripcion', 'like', '%' . $query . '%');
            }
        });
        return $q->orderBy('id')->limit(30)->get(['id', 'status', $kind === 'entidades' ? 'razon_social' : 'descripcion',
            $kind === 'entidades' ? 'rfc' : 'sku', $kind === 'entidades' ? 'tipo' : 'id_tipo']);
    }

    private function references($kind, $removeId, $keepId)
    {
        $table = $this->table($kind);
        $database = DB::connection()->getDatabaseName();
        $names = $kind === 'entidades'
            ? ['id_entidad', 'entidad_origen', 'entidad_destino']
            : ['id_modelo', 'id_modelo_kit', 'id_modelo_componente'];
        $foreign = DB::select('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ?', [$database, $table]);
        $columns = DB::select('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND COLUMN_NAME IN (' . implode(',', array_fill(0, count($names), '?')) . ')',
            array_merge([$database], $names));
        $map = [];
        foreach (array_merge($columns, $foreign) as $column) {
            if ($column->TABLE_NAME === $table) { continue; }
            $key = $column->TABLE_NAME . '.' . $column->COLUMN_NAME;
            $map[$key] = ['table' => $column->TABLE_NAME, 'column' => $column->COLUMN_NAME];
        }
        ksort($map);
        $result = [];
        foreach ($map as $reference) {
            $refTable = $reference['table'];
            $refColumn = $reference['column'];
            $sourceCount = DB::table($refTable)->where($refColumn, $removeId)->count();
            $result[] = $reference + ['count' => $sourceCount];
        }
        return $result;
    }

    private function review($kind, $keepId, $removeId, $locked = false)
    {
        $table = $this->table($kind);
        $keepId = $this->id($keepId);
        $removeId = $this->id($removeId);
        if ($keepId === $removeId) { throw new InvalidArgumentException('Selecciona dos registros diferentes.'); }
        $rows = DB::table($table)->whereIn('id', [$keepId, $removeId]);
        if ($locked) { $rows->lockForUpdate(); }
        $records = $rows->get()->keyBy('id');
        $keep = $records->get($keepId);
        $remove = $records->get($removeId);
        if (!$keep || !$remove || (int) $keep->status !== 1 || (int) $remove->status !== 1
            || $keep->deleted_at || $remove->deleted_at) {
            throw new InvalidArgumentException('Ambos registros deben existir y estar activos. Consulta de nuevo.');
        }
        $blockers = [];
        if (!Schema::hasTable('developer_merge_audit')) {
            $blockers[] = 'Falta instalar la tabla de auditoría de conciliaciones en el servidor.';
        }
        if ($kind === 'entidades') {
            if (mb_strtoupper(trim((string) $keep->rfc)) !== mb_strtoupper(trim((string) $remove->rfc))
                || !trim((string) $keep->rfc)) {
                $blockers[] = 'Los RFC no coinciden. Confirma la identidad antes de fusionar entidades.';
            }
        } else {
            if ((int) $keep->serie !== (int) $remove->serie) {
                $blockers[] = 'Uno de los modelos usa series y el otro no. Revisa el catálogo antes de fusionar.';
            }
            $priceCompanies = DB::table('modelo_precio')->whereIn('id_modelo', [$keepId, $removeId])
                ->select('id_empresa')->groupBy('id_empresa')->havingRaw('COUNT(DISTINCT id_modelo) > 1')->pluck('id_empresa');
            $priceWarning = $priceCompanies->isNotEmpty()
                ? 'Hay precios en ambos modelos para la misma empresa (' . $priceCompanies->implode(', ') . '). Se conservará el precio del modelo elegido.' : null;
            $stockWarning = null;
            foreach ([['modelo_existencias', 'id_almacen'], ['modelo_inventario', 'id_empresa_almacen']] as $stockTable) {
                list($stockName, $stockKey) = $stockTable;
                if (DB::table($stockName . ' as a')->join($stockName . ' as b', function ($join) use ($stockKey, $keepId) {
                    $join->on('a.' . $stockKey, '=', 'b.' . $stockKey)->where('b.id_modelo', '=', $keepId);
                })->where('a.id_modelo', $removeId)->exists()) {
                    $stockWarning = 'Hay existencias de ambos modelos en el mismo almacén. Sus cantidades se sumarán; confirma que representan unidades distintas.';
                    break;
                }
            }
            $sameSerial = DB::table('producto as a')->join('producto as b', function ($join) use ($keepId) {
                $join->on('a.serie', '=', 'b.serie')->where('b.id_modelo', '=', $keepId);
            })->where('a.id_modelo', $removeId)->whereNotNull('a.serie')->where('a.serie', '<>', '')->exists();
            if ($sameSerial) { $blockers[] = 'Hay números de serie repetidos entre ambos modelos. Revisa las piezas antes de fusionar.'; }
        }
        $refs = $this->references($kind, $removeId, $keepId);
        $warnings = array_values(array_filter([$priceWarning ?? null, $stockWarning ?? null]));
        if ($kind === 'entidades') {
            foreach ($refs as $ref) {
                if ($ref['table'] === 'documento' && $ref['column'] === 'id_entidad' && $ref['count']) {
                    $warnings[] = 'Los documentos históricos del duplicado pasarán a mostrar los datos finales de la entidad conservada.';
                    break;
                }
            }
        }
        $token = hash('sha256', json_encode([$kind, $keep, $remove, $refs]));
        return ['kind' => $kind, 'keep' => $keep, 'remove' => $remove, 'references' => $refs,
            'blockers' => $blockers, 'warnings' => $warnings,
            'fields' => self::FIELDS[$kind], 'confirmation_token' => $token];
    }

    public function inspect($kind, $keepId, $removeId)
    {
        return $this->review($kind, $keepId, $removeId);
    }

    private function editedFields($kind, $values, $keep, $removeId)
    {
        if (!is_array($values)) { throw new InvalidArgumentException('Los datos editados no tienen un formato válido.'); }
        $result = [];
        foreach (self::FIELDS[$kind] as $field) {
            if (!array_key_exists($field, $values)) { continue; }
            $value = $values[$field];
            if (is_array($value) || is_object($value)) {
                throw new InvalidArgumentException('El campo ' . $field . ' debe ser un valor simple.');
            }
            $result[$field] = $value === null ? null : trim((string) $value);
        }
        $name = trim((string) ($result[$kind === 'entidades' ? 'razon_social' : 'descripcion']
            ?? ($kind === 'entidades' ? $keep->razon_social : $keep->descripcion)));
        if (!$name || mb_strlen($name) > 150) { throw new InvalidArgumentException('Escribe un nombre o descripción válida.'); }
        if ($kind === 'entidades') {
            $rfc = trim((string) ($result['rfc'] ?? $keep->rfc));
            if (!$rfc || mb_strlen($rfc) > 13) { throw new InvalidArgumentException('Escribe un RFC válido.'); }
            if (!empty($result['correo']) && !filter_var($result['correo'], FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('El correo no tiene un formato válido.');
            }
            if (!empty($result['codigo_postal_fiscal']) && !preg_match('/^[0-9]{5}$/', $result['codigo_postal_fiscal'])) {
                throw new InvalidArgumentException('El código postal fiscal debe tener cinco dígitos.');
            }
            if (!empty($result['info_extra'])) {
                json_decode($result['info_extra']);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InvalidArgumentException('La información adicional debe contener JSON válido.');
                }
            }
            $result['tipo'] = 3;
        } else {
            $sku = trim((string) ($result['sku'] ?? $keep->sku));
            if (!$sku || mb_strlen($sku) > 100) { throw new InvalidArgumentException('Escribe un SKU válido.'); }
            $result['sku'] = $sku;
            if (DB::table('modelo')->where('sku', $sku)->whereNotIn('id', [$keep->id, $removeId])->exists()) {
                throw new InvalidArgumentException('El SKU final ya está asignado a otro modelo.');
            }
            if (isset($result['serie']) && (int) $result['serie'] !== (int) $keep->serie) {
                throw new InvalidArgumentException('El control por serie debe conservarse durante la conciliación.');
            }
        }
        $result['updated_at'] = date('Y-m-d H:i:s');
        return $result;
    }

    private function consolidateStock($removeId, $keepId, &$counts, &$removedDependencies)
    {
        foreach ([
            ['modelo_existencias', 'id_almacen', ['stock_inicial', 'stock', 'stock_anterior']],
            ['modelo_inventario', 'id_empresa_almacen', ['existencia', 'fisico']],
        ] as $config) {
            list($table, $key, $quantities) = $config;
            $oldRows = DB::table($table)->where('id_modelo', $removeId)->lockForUpdate()->get();
            foreach ($oldRows as $old) {
                $match = DB::table($table)->where('id_modelo', $keepId)->where($key, $old->$key)->lockForUpdate()->first();
                if (!$match) { continue; }
                $changes = [];
                foreach ($quantities as $quantity) {
                    $changes[$quantity] = (float) $match->$quantity + (float) $old->$quantity;
                }
                DB::table($table)->where('id', $match->id)->update($changes);
                $removedDependencies[] = ['table' => $table, 'row' => $old];
                DB::table($table)->where('id', $old->id)->delete();
                $counts[$table . '.consolidated'] = ($counts[$table . '.consolidated'] ?? 0) + 1;
            }
        }
    }

    private function consolidateDetails($removeId, $keepId, &$counts, &$removedDependencies)
    {
        foreach ([
            ['modelo_precio', 'id_empresa'],
            ['modelo_amazon', null],
            ['modelo_costo', null],
        ] as $config) {
            list($table, $key) = $config;
            foreach (DB::table($table)->where('id_modelo', $removeId)->lockForUpdate()->get() as $old) {
                $match = DB::table($table)->where('id_modelo', $keepId);
                if ($key) { $match->where($key, $old->$key); }
                if (!$match->exists()) { continue; }
                $removedDependencies[] = ['table' => $table, 'row' => $old];
                DB::table($table)->where('id', $old->id)->delete();
                $counts[$table . '.kept_existing'] = ($counts[$table . '.kept_existing'] ?? 0) + 1;
            }
        }
    }

    public function merge($kind, $input, $userId)
    {
        $this->table($kind);
        $keepId = $this->id($input['keep_id'] ?? null);
        $removeId = $this->id($input['remove_id'] ?? null);
        if (empty($input['confirmation_token']) || ($input['confirmation_text'] ?? '') !== 'CONCILIAR ' . $removeId) {
            throw new InvalidArgumentException('Revisa los registros y escribe CONCILIAR ' . $removeId . ' para confirmar.');
        }
        return DB::transaction(function () use ($kind, $input, $userId, $keepId, $removeId) {
            $review = $this->review($kind, $keepId, $removeId, true);
            if ($review['blockers']) { throw new InvalidArgumentException(implode(' ', $review['blockers'])); }
            if (!hash_equals($review['confirmation_token'], (string) $input['confirmation_token'])) {
                throw new InvalidArgumentException('Los registros cambiaron desde la revisión. Consulta de nuevo antes de aplicar.');
            }
            $table = $this->table($kind);
            $changes = $this->editedFields($kind, $input['fields'] ?? [], $review['keep'], $removeId);
            if ($kind === 'entidades') { $changes['updated_by_user'] = $userId; }
            $counts = [];
            $removedDependencies = [];
            if ($kind === 'productos') {
                $this->consolidateStock($removeId, $keepId, $counts, $removedDependencies);
                $this->consolidateDetails($removeId, $keepId, $counts, $removedDependencies);
            }
            foreach ($review['references'] as $ref) {
                $key = $ref['table'] . '.' . $ref['column'];
                $counts[$key] = DB::table($ref['table'])->where($ref['column'], $removeId)
                    ->update([$ref['column'] => $keepId]);
            }
            // Remove only after every reference has moved. This also frees the old SKU
            // when the operator chooses it as the surviving model's SKU.
            DB::table($table)->where('id', $removeId)->delete();
            DB::table($table)->where('id', $keepId)->update($changes);
            if ($kind === 'productos') {
                foreach (array_unique([(string) $review['keep']->sku, (string) $review['remove']->sku]) as $alias) {
                    if ($alias && $alias !== $changes['sku']) {
                        $owner = DB::table('modelo_sinonimo')->where('codigo', $alias)->where('id_modelo', '<>', $keepId)->first();
                        if ($owner) { throw new InvalidArgumentException('El código alternativo ' . $alias . ' pertenece a otro producto.'); }
                        if (!DB::table('modelo_sinonimo')->where('codigo', $alias)->where('id_modelo', $keepId)->exists()) {
                            DB::table('modelo_sinonimo')->insert(['id_modelo' => $keepId, 'id_usuario' => $userId,
                                'codigo' => $alias, 'created_at' => date('Y-m-d H:i:s')]);
                        }
                    }
                }
            }
            foreach ($review['references'] as $ref) {
                if (DB::table($ref['table'])->where($ref['column'], $removeId)->exists()) {
                    throw new InvalidArgumentException('Aparecieron nuevas referencias. Consulta de nuevo antes de aplicar.');
                }
            }
            DB::table('developer_merge_audit')->insert([
                'kind' => $kind, 'keep_id' => $keepId, 'remove_id' => $removeId, 'user_id' => $userId,
                'before_json' => json_encode(['keep' => $review['keep'], 'remove' => $review['remove'],
                    'removed_dependencies' => $removedDependencies]),
                'after_json' => json_encode(DB::table($table)->where('id', $keepId)->first()),
                'references_json' => json_encode($counts), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return ['keep_id' => $keepId, 'removed_id' => $removeId, 'references' => $counts,
                'message' => 'Conciliación aplicada. Se conservaron las referencias y se eliminó el registro duplicado.'];
        });
    }
}
