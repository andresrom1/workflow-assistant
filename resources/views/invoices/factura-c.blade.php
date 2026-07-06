<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14mm 12mm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #000; margin: 0; }
        p { margin: 3px 0; }
        .lbl { font-weight: bold; }
        .muted { color: #333; }

        .copia { text-align: center; font-size: 18px; font-weight: bold; letter-spacing: 1px;
            border: 1px solid #000; border-bottom: none; padding: 5px; }

        /* Encabezado: emisor | C | comprobante */
        .cab { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .cab td { border: 1px solid #000; vertical-align: top; padding: 10px 12px; }
        .cab .izq { width: 45%; }
        .cab .ctr { width: 10%; text-align: center; vertical-align: middle; padding: 6px 2px; }
        .cab .der { width: 45%; }
        .cab .letra { font-size: 28px; font-weight: bold; line-height: 1; }
        .cab .cod { font-size: 8px; }
        .cab .top-space { height: 22px; }
        .factura-tit { font-size: 22px; font-weight: bold; margin: 0 0 6px; }

        .banda { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .banda td { border: 1px solid #000; border-top: none; padding: 6px 12px; }

        .receptor { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 8px; }
        .receptor td { border: 1px solid #000; padding: 6px 12px; vertical-align: top; }

        .items { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 8px; }
        .items th, .items td { border: 1px solid #999; padding: 5px 6px; }
        .items th { background: #e6e6e6; font-size: 9px; text-align: center; }
        .items td { vertical-align: top; }
        .r { text-align: right; }
        .c { text-align: center; }

        .tot { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .tot td { padding: 2px 6px; }
        .tot .k { text-align: right; font-weight: bold; }
        .tot .v { text-align: right; width: 120px; }
        .tot .grande { font-size: 13px; }

        .pie { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .pie td { vertical-align: bottom; }
        .pie .qr img { width: 115px; height: 115px; }
        .pie .cae { text-align: right; }
    </style>
</head>
<body>
    @php
        $nro = str_pad((string) $invoice->numero_comprobante, 8, '0', STR_PAD_LEFT);
        $pv = str_pad((string) $invoice->pto_vta, 5, '0', STR_PAD_LEFT);
        $cod = str_pad((string) $invoice->tipo_comprobante, 3, '0', STR_PAD_LEFT);
        $money = fn ($v) => number_format((float) $v, 2, ',', '.');
        $ivaReceptor = [
            'RI' => 'IVA Responsable Inscripto',
            'MT' => 'Responsable Monotributo',
            'EX' => 'IVA Sujeto Exento',
            'CF' => 'Consumidor Final',
        ][$invoice->receptor_condicion_iva] ?? $invoice->receptor_condicion_iva;
        $copias = ['ORIGINAL', 'DUPLICADO', 'TRIPLICADO'];
    @endphp

    @foreach ($copias as $i => $copia)
        <div @if (! $loop->last) style="page-break-after: always;" @endif>

            <div class="copia">{{ $copia }}</div>

            {{-- Encabezado --}}
            <table class="cab">
                <tr>
                    <td class="izq">
                        <div class="top-space"></div>
                        <p><span class="lbl">Razón Social:</span> {{ $emisor['razon_social'] }}</p>
                        @if ($emisor['domicilio'])
                            <p><span class="lbl">Domicilio Comercial:</span> {{ $emisor['domicilio'] }}</p>
                        @endif
                        <p><span class="lbl">Condición frente al IVA:</span> {{ $emisor['condicion_iva'] }}</p>
                    </td>
                    <td class="ctr">
                        <div class="letra">C</div>
                        <div class="cod">COD. {{ $cod }}</div>
                    </td>
                    <td class="der">
                        <div class="factura-tit">FACTURA</div>
                        <p><span class="lbl">Punto de Venta:</span> {{ $pv }} &nbsp;&nbsp; <span class="lbl">Comp. Nro:</span> {{ $nro }}</p>
                        <p><span class="lbl">Fecha de Emisión:</span> {{ $invoice->fecha_comprobante->format('d/m/Y') }}</p>
                        <p style="margin-top:8px;"><span class="lbl">CUIT:</span> {{ $emisor['cuit'] }}</p>
                        @if ($emisor['ingresos_brutos'])
                            <p><span class="lbl">Ingresos Brutos:</span> {{ $emisor['ingresos_brutos'] }}</p>
                        @endif
                        @if ($emisor['inicio_actividades'])
                            <p><span class="lbl">Fecha de Inicio de Actividades:</span> {{ $emisor['inicio_actividades'] }}</p>
                        @endif
                    </td>
                </tr>
            </table>

            {{-- Período facturado --}}
            <table class="banda">
                <tr>
                    <td>
                        <span class="lbl">Período Facturado Desde:</span> {{ $invoice->fecha_servicio_desde->format('d/m/Y') }}
                        &nbsp;&nbsp; <span class="lbl">Hasta:</span> {{ $invoice->fecha_servicio_hasta->format('d/m/Y') }}
                        &nbsp;&nbsp; <span class="lbl">Fecha de Vto. para el pago:</span> {{ $invoice->fecha_vto_pago->format('d/m/Y') }}
                    </td>
                </tr>
            </table>

            {{-- Receptor --}}
            <table class="receptor">
                <tr>
                    <td style="width:38%;"><span class="lbl">CUIT:</span> {{ $invoice->receptor_cuit }}</td>
                    <td style="width:62%;"><span class="lbl">Apellido y Nombre / Razón Social:</span> {{ $invoice->receptor_razon_social }}</td>
                </tr>
                <tr>
                    <td><span class="lbl">Condición frente al IVA:</span> {{ $ivaReceptor }}</td>
                    <td><span class="lbl">Domicilio:</span> {{ $invoice->receptor_domicilio }}</td>
                </tr>
                <tr>
                    <td colspan="2"><span class="lbl">Condición de venta:</span> Contado</td>
                </tr>
            </table>

            {{-- Ítems --}}
            <table class="items">
                <thead>
                    <tr>
                        <th style="width:8%;">Código</th>
                        <th>Producto / Servicio</th>
                        <th style="width:9%;">Cantidad</th>
                        <th style="width:9%;">U. Medida</th>
                        <th style="width:13%;">Precio Unit.</th>
                        <th style="width:8%;">% Bonif</th>
                        <th style="width:12%;">Imp. Bonif.</th>
                        <th style="width:14%;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="c">{{ $invoice->codigo }}</td>
                        <td>{{ $invoice->batch->concepto ?? 'Comisiones' }}</td>
                        <td class="r">1,00</td>
                        <td class="c">unidades</td>
                        <td class="r">{{ $money($invoice->importe) }}</td>
                        <td class="r">0,00</td>
                        <td class="r">0,00</td>
                        <td class="r">{{ $money($invoice->importe) }}</td>
                    </tr>
                </tbody>
            </table>

            {{-- Totales --}}
            <table class="tot">
                <tr>
                    <td class="k">Subtotal: $</td>
                    <td class="v">{{ $money($invoice->importe) }}</td>
                </tr>
                <tr>
                    <td class="k">Importe Otros Tributos: $</td>
                    <td class="v">0,00</td>
                </tr>
                <tr>
                    <td class="k grande">Importe Total: $</td>
                    <td class="v grande">{{ $money($invoice->importe) }}</td>
                </tr>
            </table>

            {{-- QR + CAE --}}
            <table class="pie">
                <tr>
                    <td class="qr" style="width:40%;">
                        <img src="{{ $qr }}" alt="QR AFIP">
                    </td>
                    <td class="cae">
                        <p><span class="lbl">CAE N°:</span> {{ $invoice->cae }}</p>
                        <p><span class="lbl">Fecha de Vto. de CAE:</span> {{ $invoice->cae_vencimiento?->format('d/m/Y') }}</p>
                    </td>
                </tr>
            </table>
        </div>
    @endforeach
</body>
</html>
