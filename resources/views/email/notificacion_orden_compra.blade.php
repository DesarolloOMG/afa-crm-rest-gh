<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />

    <title>OMG International SA de CV.</title>
    <meta name="viewport" content="width=device-width" />
    <!-- Favicon icon -->
    <link rel="icon" href="../email-templates/img/favicon.ico" type="image/x-icon">
    <style type="text/css">
        @media only screen and (max-width: 550px),
        screen and (max-device-width: 550px) {
            body[yahoo] .buttonwrapper {
                background-color: transparent !important;
            }
            body[yahoo] .button {
                padding: 0 !important;
            }
            body[yahoo] .button a {
                background-color: #9b59b6;
                padding: 15px 25px !important;
            }
        }

        @media only screen and (min-device-width: 601px) {
            .content {
                width: 600px !important;
            }
            .col387 {
                width: 387px !important;
            }
        }
    </style>
</head>

<body bgcolor="#34495E" style="margin: 0; padding: 0;" yahoo="fix">
    <!--[if (gte mso 9)|(IE)]>
        <table width="600" align="center" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td>
        <![endif]-->
    <table align="center" border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 600px; margin-top: 15px;"
        class="content">
        <tr>
            <td align="center" bgcolor="#0073AA" style="padding: 20px 20px 20px 20px; color: #ffffff; font-family: Arial, sans-serif; font-size: 36px; font-weight: bold;">
                <img src="http://www.omg.com.mx/images/logo-omg.png" alt="OMG International" width="300" height="120" style="display: block;" />
            </td>
        </tr>
        <tr>
            <td align="center" bgcolor="#ffffff" style="padding: 40px 20px 40px 20px; color: #555555; font-family: Arial, sans-serif; font-size: 20px; line-height: 30px; border-bottom: 1px solid #f6f6f6;">
                <b>{{ $proveedor }}</b>
                <br/>Orden de compra {{ $documento }}<br><br>
                
                Estimado proveedor.<br><br>

                Se anexan productos y guias para la orden de compra {{ $documento }}. <br><br>

                <table style="width: 100%; max-width: 100%; margin-bottom: 1rem; background-color: transparent; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th>CANTIDAD</th>
                            <th>CODIGO</th>
                            <th>DESCRIPCION</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($productos as $producto)
                        <tr>
                            <td>{{ $producto->cantidad }}</td>
                            <td>{{ $producto->codigo }}</td>
                            <td>{{ $producto->descripcion }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </td>
        </tr>
        <tr>
            <td align="center" bgcolor="#dddddd" style="padding: 15px 10px 15px 10px; color: #555555; font-family: Arial, sans-serif; font-size: 12px; line-height: 18px;">
                <b>Todos los Derechos OMG International SA de CV. 2004 - {{ $anio }}</b>
                <br/>Industria Vidriera 105 &bull; Fracc. Industrial Zapopan Norte &bull; Zapopan Jalisco 45130
            </td>
        </tr>
    </table>
    <!--[if (gte mso 9)|(IE)]>
                </td>
            </tr>
        </table>
        <![endif]-->
</body>

</html>