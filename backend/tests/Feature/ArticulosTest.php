<?php

use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Http\UploadedFile;

function datosArticuloValidos(array $overrides = []): array
{
    return array_merge([
        'nombre' => 'Laptop 14 pulgadas',
        'modelo' => 'MOD-1234',
        'clave_prod_serv' => '43211503',
        'clave_unidad' => 'H87',
        'objeto_imp' => '02',
        'precio_unitario_sin_iva' => 1500.50,
    ], $overrides);
}

test('un invitado no puede acceder a articulos', function () {
    $this->getJson('/api/v1/articulos')->assertUnauthorized();
});

test('un usuario autenticado puede crear un articulo ligado a uno de sus catalogos', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create(['descuento' => 10]);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.nombre', 'Laptop 14 pulgadas');
    $response->assertJsonPath('data.catalogo_id', $catalogo->id);
    $response->assertJsonPath('data.proveedor_id', $proveedor->id);
    $response->assertJsonPath('data.precio_unitario_con_iva', 1740.58);
    $response->assertJsonPath('data.precio_con_descuento', 1350.45);
    $this->assertDatabaseHas('articulos', [
        'user_id' => $user->id,
        'catalogo_id' => $catalogo->id,
        'nombre' => 'Laptop 14 pulgadas',
        'precio_con_descuento' => 1350.45,
    ]);
});

test('no se puede crear un articulo sin catalogo', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos());

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('catalogo_id');
});

test('no se puede crear un articulo con el catalogo de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $catalogoAjeno = Catalogo::factory()->for($otro)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogoAjeno->id,
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('catalogo_id');
});

test('una clave de producto o servicio inexistente no permite crear el articulo', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'clave_prod_serv' => '99999999',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('clave_prod_serv');
});

test('una clave de unidad inexistente no permite crear el articulo', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'clave_unidad' => 'ZZZZZ',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('clave_unidad');
});

test('un objeto de impuesto fuera del enum no permite crear el articulo', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'objeto_imp' => '99',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('objeto_imp');
});

test('un precio unitario sin iva menor o igual a cero no permite crear el articulo', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'precio_unitario_sin_iva' => 0,
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('precio_unitario_sin_iva');
});

test('un nombre duplicado en el mismo catalogo es rechazado', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Laptop 14 pulgadas']);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'nombre' => 'Laptop 14 pulgadas',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('nombre');
});

test('un nombre duplicado en un catalogo distinto del mismo proveedor tambien es rechazado', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo1 = Catalogo::factory()->for($user)->for($proveedor)->create();
    $catalogo2 = Catalogo::factory()->for($user)->for($proveedor)->create();
    Articulo::factory()->for($user)->for($catalogo1)->create(['nombre' => 'Laptop 14 pulgadas']);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo2->id,
        'nombre' => 'Laptop 14 pulgadas',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('nombre');
});

test('el mismo nombre si puede repetirse en un proveedor distinto', function () {
    $user = User::factory()->create();
    $catalogo1 = Catalogo::factory()->for($user)->create();
    $catalogo2 = Catalogo::factory()->for($user)->create();
    Articulo::factory()->for($user)->for($catalogo1)->create(['nombre' => 'Laptop 14 pulgadas']);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo2->id,
        'nombre' => 'Laptop 14 pulgadas',
    ]));

    $response->assertCreated();
});

test('el mismo nombre puede reutilizarse en el proveedor tras eliminar (soft delete) el articulo que lo tenia', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Laptop 14 pulgadas']);
    $articulo->delete();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'nombre' => 'Laptop 14 pulgadas',
    ]));

    $response->assertCreated();
});

test('el listado solo muestra los articulos del usuario autenticado y permite buscar por nombre modelo o proveedor', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create(['nombre_comercial' => 'Distribuidora Acme']);
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();

    Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Laptop 14 pulgadas', 'modelo' => 'MOD-1']);
    Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Mouse inalambrico', 'modelo' => 'MOD-2']);
    Articulo::factory()->for($otro)->create(['nombre' => 'Articulo de otro usuario']);

    $response = $this->actingAs($user)->getJson('/api/v1/articulos');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    $busqueda = $this->actingAs($user)->getJson('/api/v1/articulos?search=Laptop');
    expect($busqueda->json('data'))->toHaveCount(1);

    $busquedaProveedor = $this->actingAs($user)->getJson('/api/v1/articulos?search=Acme');
    expect($busquedaProveedor->json('data'))->toHaveCount(2);
});

test('un usuario no puede ver el articulo de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $articulo = Articulo::factory()->for($otro)->create();

    $this->actingAs($user)->getJson("/api/v1/articulos/{$articulo->id}")->assertNotFound();
});

test('editar un articulo existente persiste los cambios', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create();

    $response = $this->actingAs($user)->putJson("/api/v1/articulos/{$articulo->id}", datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'nombre' => $articulo->nombre,
        'modelo' => 'MOD-ACTUALIZADO',
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.modelo', 'MOD-ACTUALIZADO');
    $this->assertDatabaseHas('articulos', ['id' => $articulo->id, 'modelo' => 'MOD-ACTUALIZADO']);
});

test('editar un articulo recalcula el precio con descuento si cambia de catalogo', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogoOrigen = Catalogo::factory()->for($user)->for($proveedor)->create(['descuento' => 0]);
    $catalogoDestino = Catalogo::factory()->for($user)->for($proveedor)->create(['descuento' => 25]);
    $articulo = Articulo::factory()->for($user)->for($catalogoOrigen)->create([
        'precio_unitario_sin_iva' => 1000,
        'precio_con_descuento' => 1000,
    ]);

    $response = $this->actingAs($user)->putJson("/api/v1/articulos/{$articulo->id}", datosArticuloValidos([
        'catalogo_id' => $catalogoDestino->id,
        'nombre' => $articulo->nombre,
        'precio_unitario_sin_iva' => 1000,
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.precio_con_descuento', 750);
    $this->assertDatabaseHas('articulos', ['id' => $articulo->id, 'catalogo_id' => $catalogoDestino->id, 'precio_con_descuento' => 750]);
});

test('eliminar un articulo lo remueve del listado pero no lo borra fisicamente (soft delete)', function () {
    $user = User::factory()->create();
    $articulo = Articulo::factory()->for($user)->create();

    $this->actingAs($user)->deleteJson("/api/v1/articulos/{$articulo->id}")->assertNoContent();

    $this->actingAs($user)->getJson('/api/v1/articulos')->assertJsonCount(0, 'data');
    $this->assertSoftDeleted('articulos', ['id' => $articulo->id]);
});

test('el catalogo de objetos de impuesto se puede consultar', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/catalogos/objetos-impuesto');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(4);
    $response->assertJsonFragment(['id' => '02', 'texto' => 'Sí objeto de impuesto']);
});

test('el catalogo de claves de producto o servicio se puede buscar por texto', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/catalogos/claves-prod-serv?q=notebook');

    $response->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
});

test('el catalogo de claves de unidad se puede buscar por texto', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/catalogos/claves-unidad?q=pieza');

    $response->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
});

test('importar un csv valido da de alta todos los articulos asociados al catalogo de la ruta', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 10]);

    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_unitario_sin_iva\n"
        ."Laptop 14 pulgadas,MOD-1,43211503,H87,02,1500.50\n"
        ."Mouse inalambrico,MOD-2,43211503,H87,02,299.99\n";
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $csv);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ]);

    $response->assertOk();
    $response->assertJsonPath('importados', 2);
    $response->assertJsonPath('errores', []);
    $this->assertDatabaseHas('articulos', ['catalogo_id' => $catalogo->id, 'nombre' => 'Laptop 14 pulgadas', 'precio_con_descuento' => 1350.45]);
    $this->assertDatabaseHas('articulos', ['catalogo_id' => $catalogo->id, 'nombre' => 'Mouse inalambrico']);
});

test('importar un csv con filas invalidas importa las validas y reporta las invalidas por fila', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_unitario_sin_iva\n"
        ."Laptop 14 pulgadas,MOD-1,43211503,H87,02,1500.50\n"
        ."Articulo con clave invalida,MOD-2,00000000,H87,02,100\n";
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $csv);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ]);

    $response->assertOk();
    $response->assertJsonPath('importados', 1);
    expect($response->json('errores'))->toHaveCount(1);
    expect($response->json('errores.0.fila'))->toBe(3);
    $this->assertDatabaseHas('articulos', ['catalogo_id' => $catalogo->id, 'nombre' => 'Laptop 14 pulgadas']);
    $this->assertDatabaseMissing('articulos', ['catalogo_id' => $catalogo->id, 'nombre' => 'Articulo con clave invalida']);
});

test('no se puede importar un csv en el catalogo de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $catalogoAjeno = Catalogo::factory()->for($otro)->create();

    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_unitario_sin_iva\n";
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $csv);

    $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogoAjeno->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ])->assertNotFound();
});

test('exportar articulos genera un csv con las columnas esperadas por la importacion', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Laptop 14 pulgadas']);

    $response = $this->actingAs($user)->get('/api/v1/articulos/exportar-csv');

    $response->assertOk();
    $contenido = $response->streamedContent();
    expect($contenido)->toContain('nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_unitario_sin_iva');
    expect($contenido)->toContain('Laptop 14 pulgadas');
});
