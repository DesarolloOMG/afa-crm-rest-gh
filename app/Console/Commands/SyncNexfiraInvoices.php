<?php

namespace App\Console\Commands;

use App\Http\Services\Nexfira\FacturacionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncNexfiraInvoices extends Command
{
    protected $signature = 'nexfira:sync-active {--limit= : Máximo de solicitudes a consultar}';

    protected $description = 'Consulta solicitudes activas de Nexfira y recupera UUID, XML y PDF cuando estén listos';

    private $service;

    public function __construct(FacturacionService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle()
    {
        if (!config('nexfira.polling.enabled', true)) {
            $this->info('La sincronización automática de Nexfira está deshabilitada.');

            return 0;
        }

        $limit = (int) ($this->option('limit') ?: config('nexfira.polling.batch_size', 25));
        $limit = max(1, min($limit, 100));
        $requests = DB::table('facturacion_solicitud as fs')
            ->where('fs.proveedor', 'nexfira')
            ->whereIn('fs.status', [
                'sending',
                'uncertain',
                'pending_approval',
                'queued',
                'processing',
                'stamped',
            ])
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('facturacion_solicitud_documento as fsd')
                    ->join('documento as d', 'd.id', '=', 'fsd.id_documento')
                    ->whereRaw('fsd.id_solicitud = fs.id')
                    ->where(function ($pending) {
                        $pending->where(function ($sale) {
                            $sale->where('d.id_tipo', 2)->where('d.id_fase', 5);
                        })->orWhere(function ($note) {
                            $note->where('d.id_tipo', 6)
                                ->whereRaw("UPPER(TRIM(COALESCE(d.uuid, ''))) IN ('', 'N/A', 'NA', 'N.A.', 'NO APLICA')");
                        })->orWhere(function ($incomplete) {
                            $incomplete->where('fs.status', 'stamped')->where('d.id_fase', 6)
                                ->whereColumn('d.uuid', 'fs.fiscal_uuid')
                                ->where(function ($missing) {
                                    $missing->whereRaw("TRIM(COALESCE(fs.serie, '')) <> '' AND TRIM(COALESCE(d.factura_serie, '')) <> TRIM(fs.serie)")
                                        ->orWhereRaw("TRIM(COALESCE(fs.folio, '')) <> '' AND TRIM(COALESCE(d.factura_folio, '')) <> TRIM(fs.folio)")
                                        ->orWhereNotExists(function ($files) {
                                            $files->select(DB::raw(1))->from('documento_factura as df')
                                                ->whereColumn('df.id_documento', 'd.id')
                                                ->whereRaw("TRIM(COALESCE(df.xml, '')) <> ''")
                                                ->whereRaw("TRIM(COALESCE(df.pdf, '')) <> ''");
                                        });
                                });
                        });
                    })
                    ->whereNull('d.deleted_at')->where('d.status', 1);
            })
            ->orderBy('fs.id')
            ->limit($limit)
            ->get(['fs.id', 'fs.status', 'fs.updated_by']);

        $completed = 0;
        $pending = 0;
        $failed = 0;

        foreach ($requests as $request) {
            try {
                $result = $this->service->sync((int) $request->id, (int) $request->updated_by);
                if (($result['status'] ?? null) === 'stamped'
                    && ($result['documents_status'] ?? null) === 'retrieved') {
                    $completed++;
                } else {
                    $pending++;
                }
            } catch (Throwable $exception) {
                $failed++;
                Log::warning('No fue posible sincronizar una solicitud Nexfira.', [
                    'request_id' => (int) $request->id,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $processed = count($requests);
        $this->info(
            'Nexfira: ' . $processed . ' procesada(s), '
            . $completed . ' completada(s), '
            . $pending . ' pendiente(s), '
            . $failed . ' fallida(s).'
        );

        return $failed > 0 ? 1 : 0;
    }
}
