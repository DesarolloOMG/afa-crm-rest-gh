<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\TicketArchivo;
use App\Models\Usuario;

class Ticket extends Model
{
    use SoftDeletes;

    protected $table = 'ticket';

    protected $fillable = [
        'titulo',
        'descripcion',
        'estado',
        'resolucion',
        'cerrado_por',
        'motivo_cierre',
        'creado_por',
        'asignado_por',
        'asignado_a',
        'assigned_at',
        'started_at',
        'resolved_at',
        'closed_at'
    ];

    public function creador()
    {
        return $this->hasOne(Usuario::class, "id", "creado_por");
    }

    public function tecnico()
    {
        return $this->hasOne(Usuario::class, "id", "asignado_a");
    }

    public function archivos()
    {
        return $this->hasMany(TicketArchivo::class, "id_ticket", "id");
    }
}
