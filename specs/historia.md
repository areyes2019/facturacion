Como: Usuario registrado.

Quiero: Generar una cotizacion

Para: Enviarla por correo y luego convertirla en factura, si el cliente lo desa

Criterios de Aceptación:

Éxito:

Dado que estoy en la pantalla de cotizaciones, cuando selecciono un cliente y un articlo o varios articulos las sumas se desglosan al final de la factura. Se envia por la api de factura.com se timbra y se guardan los sellos en la base de datos

Error:

Dado que estoy en la pantalla de facturacion, cuando ingreso los datos estos no estan completos o son erroneos y el endpoint de factura.com me da msg de error