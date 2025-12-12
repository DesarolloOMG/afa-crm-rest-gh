<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Ticket;

class TicketArchivo extends Model
{
    protected $table = 'ticket_archivos';

    protected $fillable = [
        'id_ticket',
        'dropbox',
        'nombre',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }
}
