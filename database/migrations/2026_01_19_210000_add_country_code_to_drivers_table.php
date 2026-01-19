<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Agrega columna country_code para almacenar el código de país del teléfono.
     * Esto es necesario porque:
     * 1. Los números de Samsara pueden venir sin código de país
     * 2. WhatsApp en México requiere formato especial (+521XXXXXXXXXX)
     * 3. SMS y llamadas usan formato diferente (+52XXXXXXXXXX)
     */
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('country_code', 5)->nullable()->after('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('country_code');
        });
    }
};
