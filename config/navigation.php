<?php

return [
    'main' => [
        [
            'label' => 'Rankings',
            'children' => [
                [
                    'label' => 'Ranking de ventas',
                    'route' => 'leaderboard.sales',
                ],
                [
                    'label' => 'Ranking de compras',
                    'route' => 'leaderboard.purchases',
                ],
                [
                    'label' => 'Hot & Cold',
                    'route' => 'leaderboard.vehicles',
                ],
            ],
        ],
        [
            'label' => 'Currículums',
            'route' => 'curriculums.index',
        ],
        [
            'label' => 'Tickets',
            'route' => 'tickets.index',
        ],
        [
            'label' => 'Empresa',
            'children' => [
                [
                    'label' => 'Quiénes somos',
                    'route' => 'empresa.index',
                ],
                [
                    'label' => 'Vídeos',
                    'route' => 'videos',
                ],
                [
                    'label' => 'Reseñas',
                    'route' => 'reviews.index',
                ],
                [
                    'label' => 'Informes HR',
                    'route' => 'tools.informes',
                ],
                [
                    'label' => 'Informes Tickets',
                    'route' => 'tickets.reports',
                ],
            ],
        ],
        [
            'label' => 'Comunicación',
            'children' => [
                [
                    'label' => 'Agenda',
                    'route' => 'agenda.index',
                ],
                [
                    'label' => 'Tablón',
                    'route' => 'tablon.index',
                ],
                [
                    'label' => 'Chat',
                    'route' => 'chat.beta',
                ],
            ],
        ],
    ],

    'footer' => [
        'platform' => [
            [
                'label' => 'Inicio',
                'route' => 'home',
            ],
            [
                'label' => 'Informes HR',
                'route' => 'tools.informes',
            ],
            [
                'label' => 'Agenda',
                'route' => 'agenda.index',
            ],
            [
                'label' => 'Tickets',
                'route' => 'tickets.index',
            ],
            [
                'label' => 'Chat',
                'route' => 'chat.beta',
            ],
            [
                'label' => 'Currículums',
                'route' => 'curriculums.index',
            ],
            [
                'label' => 'Vídeos',
                'route' => 'videos',
            ],
            [
                'label' => 'Quiénes somos',
                'route' => 'empresa.index',
            ],
            [
                'label' => 'Reseñas',
                'route' => 'reviews.index',
            ],
            [
                'label' => 'Ranking ventas',
                'route' => 'leaderboard.sales',
            ],
            [
                'label' => 'Ranking compras',
                'route' => 'leaderboard.purchases',
            ],
            [
                'label' => 'Ranking coches',
                'route' => 'leaderboard.vehicles',
            ],
        ],
    ],
];
