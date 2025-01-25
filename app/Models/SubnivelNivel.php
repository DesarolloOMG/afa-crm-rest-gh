<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Subnivel;

class SubnivelNivel extends Model
{
    // use HasFactory;
    protected $table = 'subnivel_nivel';

    public function niveles() {
        return $this->hasMany(Subnivel::class, "id_nivel");
    }

    public static function existe($id)
    {
        return self::where('subnivel', $id)->first();
        
    }

    public static function consulta($id)
    {
        return self::select("subnivel_nivel.id", "subnivel.subnivel")
            ->join("subnivel", "subnivel_nivel.id_subnivel", "=", "subnivel.id")
            ->where("subnivel_nivel.id_nivel", $id)
            ->get();
    }

    public static function agregar_user_subnivel_nivel($datos)
    {
        $query = new Subnivel();
        $query->create($datos);
    }

    public static function editar_sub_nivel($id, $nivel)
    {
        $query = SubNivel::find($id);
        $query->id_nivel = $nivel;
        $query->save();
    }
}
