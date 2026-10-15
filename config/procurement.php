<?php

return [

    /*
    | Выгрузка новых цен поставщиков в Excel под загрузку в 1С (шаблон заказчика
    | «Шаблон выгрузки цен.xlsx»): M-артикул, затем по каждому поставщику — цена,
    | дата предложения, валюта. Поставщики шаблона идут первыми в его порядке, под
    | названиями, как в 1С; остальные — по числу артикулов.
    */
    'price_export' => [
        'template_suppliers' => [
            'BUENO LIFT',
            'JF',
            'OTI  (ES Escalator Parts Company)',
            'OSS',
            'EVE Elevator Parts',
            'YuanQi (ЮаньЧи) Китай',
        ],
        'disk' => env('PRICE_EXPORT_DISK', 'local'),
        'directory' => 'exports/prices',
    ],

];
