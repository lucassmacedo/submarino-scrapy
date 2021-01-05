<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('code')->unique();
            $table->string('barcode', 20)->nullable()->unique();
            $table->string('name');
            $table->string('href');
            $table->float('price', 10, 2)->nullable();
            $table->longText('description')->nullable();
            $table->jsonb('images')->nullable();
            $table->jsonb('attributes')->nullable();
            $table->timestamps();

            $table->index('code');
            $table->index(['code', 'barcode']);
        });

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('products');
    }
}
