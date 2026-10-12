<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    /** Catálogo inicial de INTEGRALEADS (honorarios referenciales en pesos; se ajustan en Catálogo). */
    public function run(): void
    {
        if (Service::count() > 0) {
            return;
        }

        // [categoría, nombre, descripción, entregables, cobro, unidad, precio en pesos (referencial: se ajusta en Catálogo)]
        $catalog = [
            ['Regularizaciones', 'Regularización de vivienda (Ley del Mono)', 'Regularización de viviendas de hasta 140 m² construidas sin permiso o sin recepción, bajo la Ley 20.898 («Ley del Mono»).', ['Visita y levantamiento de la vivienda', 'Planos y memoria de la regularización', 'Ingreso y seguimiento del trámite en la DOM', 'Entrega del expediente y certificados obtenidos'], 'one_time', 'proyecto', 650000],
            ['Regularizaciones', 'Regularización de ampliaciones y obras sin permiso', 'Regularización de ampliaciones, segundos pisos, quinchos y otras edificaciones sin permiso, según la normativa que corresponda.', ['Revisión de antecedentes y normativa', 'Levantamiento y planos', 'Ingreso y seguimiento del trámite municipal', 'Entrega del expediente'], 'one_time', 'proyecto', 900000],
            ['Regularizaciones', 'Regularización de local comercial y cambio de destino', 'Regularización de locales y oficinas, o cambio de destino de una propiedad, con todos los antecedentes para la patente comercial.', ['Revisión normativa y de uso de suelo', 'Planos y memoria del cambio de destino', 'Ingreso y seguimiento del trámite', 'Entrega de documentos para patente'], 'one_time', 'proyecto', 850000],
            ['Regularizaciones', 'Recepción final de obra', 'Gestión de la recepción final de una obra nueva o ampliación: antecedentes, inspección y certificados.', ['Revisión del expediente de obra', 'Reunión de antecedentes y certificados', 'Ingreso y seguimiento en la DOM', 'Entrega del certificado de recepción'], 'one_time', 'proyecto', 550000],
            ['Regularizaciones', 'Permiso de edificación u obra menor', 'Permiso para obra nueva, ampliación, remodelación o demolición menor, con los planos y trámites necesarios.', ['Anteproyecto y planos de arquitectura', 'Ingreso y seguimiento del permiso', 'Respuesta a observaciones de la DOM', 'Entrega del permiso'], 'one_time', 'proyecto', 750000],
            ['Regularizaciones', 'Asesoría normativa y certificados previos', 'Revisión de una propiedad: certificados, normativa aplicable y qué se puede regularizar o construir, con un informe claro.', ['Revisión de certificados y antecedentes', 'Informe de situación y alternativas', 'Reunión de presentación'], 'one_time', 'proyecto', 120000],
            ['Planos', 'Levantamiento y planimetría (as built)', 'Medición en terreno y planos del estado actual de una vivienda, oficina o local.', ['Visita y medición en terreno', 'Planos de plantas, cortes y elevaciones', 'Archivos en PDF y DWG'], 'one_time', 'proyecto', 220000],
            ['Planos', 'Planos de arquitectura para proyecto o remodelación', 'Planos de arquitectura para remodelaciones y ampliaciones, listos para cotizar o ejecutar.', ['Plantas, cortes y elevaciones', 'Cuadro de superficies', 'Especificaciones técnicas básicas', 'Archivos en PDF y DWG'], 'one_time', 'proyecto', 450000],
            ['Planos', 'Planos para trámite municipal', 'Planos y documentos con el formato que exige la municipalidad para ingresar un trámite.', ['Planos y cuadros normativos', 'Memoria explicativa', 'Formularios del trámite'], 'one_time', 'proyecto', 300000],
            ['Diseño de interiores', 'Diseño de interiores · Departamentos', 'Proyecto de diseño interior para departamentos: distribución, materialidad, iluminación y mobiliario.', ['Levantamiento y definición de necesidades', 'Propuesta de distribución y ambientes', 'Planos de diseño, materialidad e iluminación', 'Selección de mobiliario y terminaciones'], 'one_time', 'proyecto', 900000],
            ['Diseño de interiores', 'Diseño de interiores · Casas', 'Proyecto de diseño interior para casas: ambientes, circulaciones, materialidad, iluminación y mobiliario.', ['Levantamiento y definición de necesidades', 'Propuesta de distribución y ambientes', 'Planos de diseño, materialidad e iluminación', 'Selección de mobiliario y terminaciones'], 'one_time', 'proyecto', 1400000],
            ['Diseño de interiores', 'Diseño de interiores · Oficinas', 'Diseño de oficinas funcionales y con identidad: puestos de trabajo, salas, circulaciones, iluminación y mobiliario.', ['Levantamiento y programa de necesidades', 'Layout y propuesta de ambientes', 'Planos de diseño, materialidad e iluminación', 'Mobiliario y terminaciones'], 'one_time', 'proyecto', 1600000],
            ['Diseño de interiores', 'Diseño de interiores · Retail y locales comerciales', 'Diseño de locales comerciales y tiendas: recorrido del cliente, exhibición, iluminación e imagen de marca.', ['Levantamiento y programa del local', 'Propuesta de layout y exhibición', 'Planos de diseño, materialidad e iluminación', 'Mobiliario y terminaciones'], 'one_time', 'proyecto', 1800000],
            ['Diseño de interiores', 'Diseño de stands y espacios efímeros', 'Diseño de stands para ferias, eventos y espacios temporales, con planos de montaje.', ['Concepto y propuesta visual', 'Planos de montaje y detalles', 'Coordinación con el proveedor de montaje'], 'one_time', 'proyecto', 1100000],
            ['Obra', 'Gestión y supervisión de obra', 'Administración de la obra completa o supervisión de contratistas, con control de plazos y calidad. La obra se cotiza y se cobra aparte de los honorarios.', ['Presupuesto y programa de obra', 'Coordinación de contratistas', 'Visitas de supervisión y control de calidad', 'Recepción y entrega'], 'one_time', 'proyecto', 0],
        ];

        foreach ($catalog as $i => [$category, $name, $description, $deliverables, $billing, $unit, $price]) {
            Service::create(compact('category', 'name', 'description', 'deliverables', 'billing', 'unit', 'price') + ['currency' => 'CLP', 'sort_order' => $i]);
        }
    }
}
