<?php

/*
|--------------------------------------------------------------------------
| Ride offer platform catalogue
|--------------------------------------------------------------------------
|
| Canonical platform and service type codes shared by the API and the mobile
| clients (`GET /api/v1/ride-offers/catalog`). Codes are snake_case and must
| stay stable: they are persisted in `ride_offers.service_type` and used by the
| profitability calculations. Human labels and OCR match aliases live here so
| that a new service type ships as configuration instead of a code change.
|
| `aliases` are matched case-insensitively against OCR text after stripping
| accents, punctuation and whitespace. Keep them short and distinctive.
|
*/

return [

    'version' => '1',

    'platforms' => [

        'uber' => [
            'label' => 'Uber',
            'aliases' => ['uber'],
            'default_service_type' => null,
            'service_types' => [
                'uberx' => ['label' => 'UberX', 'aliases' => ['uberx', 'uber x']],
                'uberx_priority' => ['label' => 'UberX Priority', 'aliases' => ['uber priority', 'uberx priority', 'priority']],
                'uberx_share' => ['label' => 'UberX Share', 'aliases' => ['uber juntos', 'uberx share', 'uber share', 'juntos']],
                'comfort' => ['label' => 'Uber Comfort', 'aliases' => ['uber comfort', 'comfort']],
                'comfort_electric' => ['label' => 'Uber Comfort Electric', 'aliases' => ['uber comfort electric', 'comfort electric']],
                'black' => ['label' => 'Uber Black', 'aliases' => ['uber black', 'black']],
                'black_suv' => ['label' => 'Uber Black SUV', 'aliases' => ['uber black suv', 'black suv']],
                'xl' => ['label' => 'UberXL', 'aliases' => ['uberxl', 'uber xl']],
                'pet' => ['label' => 'Uber Pet', 'aliases' => ['uber pet', 'pet']],
                'electric' => ['label' => 'Uber Electric', 'aliases' => ['uber electric', 'electric']],
                'van' => ['label' => 'Uber Van', 'aliases' => ['uber van', 'van']],
                'taxi' => ['label' => 'Uber Taxi', 'aliases' => ['uber taxi']],
                'reserve' => ['label' => 'Uber Reserve', 'aliases' => ['uber reserve', 'reserve']],
                'connect' => ['label' => 'Uber Connect', 'aliases' => ['uber connect', 'connect']],
                'intercity' => ['label' => 'Uber Intercity', 'aliases' => ['uber intercity', 'interurbano']],
                'moto' => ['label' => 'Uber Moto', 'aliases' => ['uber moto', 'moto']],
                'auto' => ['label' => 'Uber Auto', 'aliases' => ['uber auto', 'auto rickshaw']],
            ],
        ],

        'didi' => [
            'label' => 'DiDi',
            'aliases' => ['didi', 'di di'],
            'default_service_type' => null,
            'service_types' => [
                'express' => ['label' => 'DiDi Express', 'aliases' => ['express', 'express nuevo', 'didi express']],
                'taxi' => ['label' => 'DiDi Taxi', 'aliases' => ['didi taxi', 'taxi']],
                'set_your_fare' => ['label' => 'Set your fare', 'aliases' => ['set your fare', 'establece tu tarifa']],
                'moto' => ['label' => 'DiDi Moto', 'aliases' => ['didi moto', 'moto']],
                'comfort' => ['label' => 'DiDi Comfort', 'aliases' => ['didi comfort', 'comfort']],
                'scheduled' => ['label' => 'DiDi Programado', 'aliases' => ['programado', 'scheduled', 'priority']],
            ],
        ],

        'indrive' => [
            'label' => 'inDrive',
            'aliases' => ['indrive', 'in drive'],
            'default_service_type' => null,
            'service_types' => [
                'city' => ['label' => 'inDrive City', 'aliases' => ['city', 'ciudad']],
                'intercity' => ['label' => 'inDrive Intercity', 'aliases' => ['intercity', 'interurbano']],
                'courier' => ['label' => 'inDrive Courier', 'aliases' => ['courier', 'envio']],
            ],
        ],

        'lyft' => [
            'label' => 'Lyft',
            'aliases' => ['lyft'],
            'default_service_type' => null,
            'service_types' => [],
        ],

        'custom' => [
            'label' => 'Custom platform',
            'aliases' => [],
            'default_service_type' => null,
            'service_types' => [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Availability types
    |--------------------------------------------------------------------------
    |
    | How the offer was assigned to the driver. Persisted in
    | `ride_offers.availability_type`.
    |
    */

    'availability_types' => [
        'exclusive' => ['label' => 'Exclusive offer', 'aliases' => ['exclusivo', 'exclusiva', 'exclusive']],
        'priority' => ['label' => 'Priority pickup', 'aliases' => ['viaje prioritario', 'priority pickup']],
        'available_trip' => ['label' => 'Available trip', 'aliases' => ['viaje disponible', 'available trip']],
        'set_your_fare' => ['label' => 'Set your fare', 'aliases' => ['set your fare', 'establece tu tarifa']],
        'metered' => ['label' => 'Metered fare', 'aliases' => ['taximetro', 'metered']],
        'unknown' => ['label' => 'Unknown', 'aliases' => []],
    ],

    /*
    |--------------------------------------------------------------------------
    | Screens that are not an offer
    |--------------------------------------------------------------------------
    |
    | Detection hints used to reject a capture before it reaches the parsers.
    | Example: DiDi "Centro de viajes" lists requests but is not an offer.
    |
    */

    'non_offer_screens' => [
        'didi' => ['centro de viajes', 'no hay mas solicitudes'],
    ],

];
