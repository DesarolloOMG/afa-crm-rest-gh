<?php

namespace App\Http\Services;

use DomainException;
use Illuminate\Support\Facades\DB;

class OdcReceptionValidator
{
    // A shared lock also covers two different ODCs receiving a new serial at once.
    public function run(callable $operation)
    {
        $connection = DB::connection();
        $mysql = $connection->getDriverName() === 'mysql';
        $lock = 'afa.odc.recepcion.series';
        if ($mysql && (int)$connection->selectOne('SELECT GET_LOCK(?, 15) AS acquired', [$lock])->acquired !== 1) {
            throw new DomainException('Hay otra recepción en proceso. Intente nuevamente.');
        }
        try {
            return $connection->transaction($operation);
        } finally {
            if ($mysql) {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            }
        }
    }

    public function validate($data)
    {
        $movements = DB::table('movimiento as m')->join('modelo as mo', 'mo.id', '=', 'm.id_modelo')
            ->where('m.id_documento', $data->id)
            ->get(['m.id', 'm.cantidad', 'mo.serie'])->keyBy('id');
        $links = DB::table('movimiento_producto as mp')->join('movimiento as m', 'm.id', '=', 'mp.id_movimiento')
            ->join('producto as p', 'p.id', '=', 'mp.id_producto')->where('m.id_documento', $data->id)
            ->get(['mp.id_movimiento', 'p.id', 'p.serie']);
        $seen = [];
        $byId = [];
        $counts = [];
        foreach ($links as $link) {
            $key = $this->key($link->serie);
            if (isset($seen[$key])) {
                throw new DomainException('La ODC contiene series duplicadas. Corrija la recepción antes de finalizar.');
            }
            $seen[$key] = true;
            $byId[(int)$link->id] = $link;
            $counts[$link->id_movimiento] = ($counts[$link->id_movimiento] ?? 0) + 1;
        }

        $submitted = [];
        $submittedMovements = [];
        foreach (($data->productos ?? []) as $product) {
            if (isset($submittedMovements[$product->id]) || !isset($movements[$product->id])) {
                throw new DomainException('El renglón de recepción no pertenece a la ODC o está repetido.');
            }
            $submittedMovements[$product->id] = true;
            $movement = $movements[$product->id];
            if ((bool)$product->serie !== (bool)$movement->serie) {
                throw new DomainException('El tipo de producto no coincide con el renglón de la ODC.');
            }
            // Quantities and serialization are defined by the database, not the client.
            $product->cantidad = $movement->cantidad;
            if (!$movement->serie) {
                continue;
            }
            foreach (($product->series ?? []) as $serial) {
                $serial->serie = trim($serial->serie);
                $key = $this->key($serial->serie);
                if (isset($submitted[$key])) {
                    throw new DomainException('La recepción contiene una serie repetida.');
                }
                $submitted[$key] = true;
                if ((int)$serial->id !== 0) {
                    $existing = $byId[(int)$serial->id] ?? null;
                    if (!$existing || (int)$existing->id_movimiento !== (int)$product->id || $this->key($existing->serie) !== $key) {
                        throw new DomainException('La serie no pertenece a la recepción indicada.');
                    }
                } else {
                    if (isset($seen[$key])) {
                        throw new DomainException('La serie ya fue recibida en esta ODC.');
                    }
                    $seen[$key] = true;
                    $counts[$product->id] = ($counts[$product->id] ?? 0) + 1;
                }
            }
            if (($counts[$product->id] ?? 0) > $movement->cantidad) {
                throw new DomainException('La cantidad de series registrada excede la cantidad de la orden de compra.');
            }
        }

        // Include saved links even if finalizar arrives without productos.
        if ($seen) {
            $otherOdc = DB::table('producto as p')->join('movimiento_producto as mp', 'mp.id_producto', '=', 'p.id')
                ->join('movimiento as m', 'm.id', '=', 'mp.id_movimiento')
                ->join('documento as d', 'd.id', '=', 'm.id_documento')
                ->where('d.id_tipo', 0)->where('d.id', '<>', $data->id)
                ->whereIn(DB::raw('UPPER(TRIM(p.serie))'), array_map(function ($key) {
                    return substr($key, 2);
                }, array_keys($seen)))->value('d.id');
            if ($otherOdc !== null) {
                throw new DomainException('La recepción contiene series ya vinculadas a la ODC ' . $otherOdc . '.');
            }
        }
    }

    private function key($serial)
    {
        $key = strtoupper(trim($serial));
        if ($key === '') {
            throw new DomainException('La serie no puede estar vacía.');
        }
        // Keep numeric serials as strings (including their leading zeroes).
        return 's:' . $key;
    }
}
