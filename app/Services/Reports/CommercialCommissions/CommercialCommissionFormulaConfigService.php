<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\CommercialCommissionMonthSetting;
use App\Models\SalesforceOpportunity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CommercialCommissionFormulaConfigService
{
    public const VERSION = '2026-09-01.1';

    private const AREA_MANAGER_DEFINITIONS = [
        'david-baeza' => 'David Baeza',
        'nicolas-fernandez' => 'Nicolas Fernandez',
        'kosta-plamenov' => 'Kosta Plamenov',
        'luis-lopez' => 'Luis Lopez',
    ];

    private const AREA_MANAGER_BOOTSTRAP_ASSIGNMENTS = [
        '2026-04' => [
            'alicante' => ['label' => 'Alicante', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 35, 'benefit' => 33320, 'guarantee' => 11900, 'purchases' => 28]],
            'murcia' => ['label' => 'Murcia', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 35, 'benefit' => 33320, 'guarantee' => 11900, 'purchases' => 16]],
            'valencia' => ['label' => 'Valencia', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 30, 'benefit' => 31416, 'guarantee' => 10200, 'purchases' => 16]],
            'paterna' => ['label' => 'Paterna', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 30, 'benefit' => 28560, 'guarantee' => 10200, 'purchases' => 27]],
            'sedavi' => ['label' => 'Sedavi', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 30, 'benefit' => 28560, 'guarantee' => 12750, 'purchases' => 18]],
            'castellon' => ['label' => 'Castellon', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 20, 'benefit' => 19040, 'guarantee' => 6800, 'purchases' => 10]],
            'villareal' => ['label' => 'Villareal', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 25, 'benefit' => 26775, 'guarantee' => 8500, 'purchases' => 15]],
            'elche' => ['label' => 'Elche', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 20, 'benefit' => 19040, 'guarantee' => 8500, 'purchases' => 12]],
            'alcoy' => ['label' => 'Alcoy', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 20, 'benefit' => 22848, 'guarantee' => 10200, 'purchases' => 12]],
            'bilbao' => ['label' => 'Bilbao', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 40, 'benefit' => 38080, 'guarantee' => 13600, 'purchases' => 24]],
            'fontellas' => ['label' => 'Fontellas', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 30, 'benefit' => 28560, 'guarantee' => 10200, 'purchases' => 15]],
            'pamplona' => ['label' => 'Pamplona', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 40, 'benefit' => 38080, 'guarantee' => 13600, 'purchases' => 20]],
            'san-sebastian' => ['label' => 'San Sebastian', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 25, 'benefit' => 23800, 'guarantee' => 10625, 'purchases' => 15]],
            'zaragoza' => ['label' => 'Zaragoza', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 45, 'benefit' => 42840, 'guarantee' => 15300, 'purchases' => 27]],
            'gijon' => ['label' => 'Gijon', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 30, 'benefit' => 29988, 'guarantee' => 12750, 'purchases' => 18]],
            'a-coruna' => ['label' => 'A Coruna', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 30, 'benefit' => 31416, 'guarantee' => 12750, 'purchases' => 18]],
            'valladolid' => ['label' => 'Valladolid', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 30, 'benefit' => 28560, 'guarantee' => 12750, 'purchases' => 18]],
            'badalona' => ['label' => 'Badalona', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 25, 'benefit' => 23800, 'guarantee' => 8500, 'purchases' => 18]],
            'girona' => ['label' => 'Girona', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 30, 'benefit' => 28560, 'guarantee' => 10200, 'purchases' => 24]],
            'lleida' => ['label' => 'Lleida', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 20, 'benefit' => 19040, 'guarantee' => 6800, 'purchases' => 12]],
            'llica-de-valls' => ['label' => 'Llica de Valls', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 20, 'benefit' => 19040, 'guarantee' => 6800, 'purchases' => 16]],
            'manresa' => ['label' => 'Manresa', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 22, 'benefit' => 20944, 'guarantee' => 7480, 'purchases' => 13]],
            'sant-boi' => ['label' => 'Sant Boi', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 50, 'benefit' => 59500, 'guarantee' => 25500, 'purchases' => 30]],
            'alcala-de-guadaira' => ['label' => 'Alcala de Guadaira', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 35, 'benefit' => 45815, 'guarantee' => 20825, 'purchases' => 21]],
            'malaga' => ['label' => 'Malaga', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 28, 'benefit' => 29322, 'guarantee' => 14280, 'purchases' => 25]],
            'malaga-centro' => ['label' => 'Malaga Centro', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 20, 'benefit' => 20944, 'guarantee' => 10200, 'purchases' => 12]],
            'sevilla' => ['label' => 'Sevilla', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 40, 'benefit' => 42840, 'guarantee' => 20400, 'purchases' => 28]],
            'palma' => ['label' => 'Palma', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 40, 'benefit' => 38080, 'guarantee' => 20400, 'purchases' => 30]],
            'alcobendas' => ['label' => 'Alcobendas', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 25, 'benefit' => 23800, 'guarantee' => 10625, 'purchases' => 20]],
            'rivas' => ['label' => 'Rivas', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 50, 'benefit' => 47600, 'guarantee' => 17000, 'purchases' => 30]],
            'torrejon-de-ardoz' => ['label' => 'Torrejon de Ardoz', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 50, 'benefit' => 47600, 'guarantee' => 17000, 'purchases' => 30]],
            'collado-villalba' => ['label' => 'Collado Villalba', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 28, 'benefit' => 29322, 'guarantee' => 14280, 'purchases' => 17]],
            'dos-hermanas' => ['label' => 'Dos Hermanas', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 20, 'benefit' => 19040, 'guarantee' => 8500, 'purchases' => 12]],
        ],
        '2026-05' => [
            'alicante' => ['label' => 'Alicante', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 35, 'benefit' => 33320, 'guarantee' => 11900, 'purchases' => 28]],
            'murcia' => ['label' => 'Murcia', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 28, 'benefit' => 26656, 'guarantee' => 11900, 'purchases' => 16]],
            'valencia' => ['label' => 'Valencia', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 30, 'benefit' => 31416, 'guarantee' => 10200, 'purchases' => 16]],
            'paterna' => ['label' => 'Paterna', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 28, 'benefit' => 27989, 'guarantee' => 11900, 'purchases' => 27]],
            'sedavi' => ['label' => 'Sedavi', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 30, 'benefit' => 28560, 'guarantee' => 12750, 'purchases' => 18]],
            'castellon' => ['label' => 'Castellon', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 20, 'benefit' => 19040, 'guarantee' => 6800, 'purchases' => 10]],
            'villareal' => ['label' => 'Villareal', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 25, 'benefit' => 26775, 'guarantee' => 8500, 'purchases' => 15]],
            'elche' => ['label' => 'Elche', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 20, 'benefit' => 19040, 'guarantee' => 8500, 'purchases' => 12]],
            'alcoy' => ['label' => 'Alcoy', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 20, 'benefit' => 19992, 'guarantee' => 8500, 'purchases' => 12]],
            'bilbao' => ['label' => 'Bilbao', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 38, 'benefit' => 36176, 'guarantee' => 12920, 'purchases' => 24]],
            'fontellas' => ['label' => 'Fontellas', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 28, 'benefit' => 26656, 'guarantee' => 11900, 'purchases' => 14]],
            'pamplona' => ['label' => 'Pamplona', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 35, 'benefit' => 33320, 'guarantee' => 11900, 'purchases' => 18]],
            'san-sebastian' => ['label' => 'San Sebastian', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 20, 'benefit' => 19040, 'guarantee' => 8500, 'purchases' => 12]],
            'zaragoza' => ['label' => 'Zaragoza', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 45, 'benefit' => 42840, 'guarantee' => 15300, 'purchases' => 27]],
            'gijon' => ['label' => 'Gijon', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 30, 'benefit' => 28560, 'guarantee' => 12750, 'purchases' => 18]],
            'a-coruna' => ['label' => 'A Coruna', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 30, 'benefit' => 31416, 'guarantee' => 12750, 'purchases' => 18]],
            'valladolid' => ['label' => 'Valladolid', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 28, 'benefit' => 26656, 'guarantee' => 11900, 'purchases' => 17]],
            'badalona' => ['label' => 'Badalona', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 20, 'benefit' => 19040, 'guarantee' => 8500, 'purchases' => 15]],
            'girona' => ['label' => 'Girona', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 25, 'benefit' => 29750, 'guarantee' => 10625, 'purchases' => 24]],
            'lleida' => ['label' => 'Lleida', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 18, 'benefit' => 17136, 'guarantee' => 7650, 'purchases' => 12]],
            'llica-de-valls' => ['label' => 'Llica de Valls', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 18, 'benefit' => 17136, 'guarantee' => 7650, 'purchases' => 14]],
            'manresa' => ['label' => 'Manresa', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 22, 'benefit' => 20944, 'guarantee' => 7480, 'purchases' => 13]],
            'sant-boi' => ['label' => 'Sant Boi', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 55, 'benefit' => 65450, 'guarantee' => 32725, 'purchases' => 39]],
            'alcala-de-guadaira' => ['label' => 'Alcala de Guadaira', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 30, 'benefit' => 44268, 'guarantee' => 17850, 'purchases' => 18]],
            'malaga' => ['label' => 'Malaga', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 30, 'benefit' => 33558, 'guarantee' => 15300, 'purchases' => 12]],
            'malaga-centro' => ['label' => 'Malaga Centro', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 20, 'benefit' => 22848, 'guarantee' => 10200, 'purchases' => 22]],
            'sevilla' => ['label' => 'Sevilla', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 38, 'benefit' => 43411, 'guarantee' => 15504, 'purchases' => 28]],
            'palma' => ['label' => 'Palma', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 40, 'benefit' => 38080, 'guarantee' => 20400, 'purchases' => 30]],
            'alcobendas' => ['label' => 'Alcobendas', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 25, 'benefit' => 23800, 'guarantee' => 10625, 'purchases' => 20]],
            'rivas' => ['label' => 'Rivas', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 45, 'benefit' => 42840, 'guarantee' => 19125, 'purchases' => 27]],
            'torrejon-de-ardoz' => ['label' => 'Torrejon de Ardoz', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 48, 'benefit' => 45696, 'guarantee' => 16320, 'purchases' => 30]],
            'collado-villalba' => ['label' => 'Collado Villalba', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 25, 'benefit' => 26775, 'guarantee' => 10625, 'purchases' => 17]],
            'dos-hermanas' => ['label' => 'Dos Hermanas', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 15, 'benefit' => 14280, 'guarantee' => 6375, 'purchases' => 9]],
        ],
        '2026-06' => [
            'alicante' => ['label' => 'Alicante', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 35, 'benefit' => 33320, 'guarantee' => 11900, 'purchases' => 28]],
            'murcia' => ['label' => 'Murcia', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 28, 'benefit' => 26656, 'guarantee' => 11900, 'purchases' => 16]],
            'valencia' => ['label' => 'Valencia', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 30, 'benefit' => 31416, 'guarantee' => 10200, 'purchases' => 16]],
            'paterna' => ['label' => 'Paterna', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 28, 'benefit' => 26656, 'guarantee' => 10200, 'purchases' => 27]],
            'sedavi' => ['label' => 'Sedavi', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 30, 'benefit' => 28560, 'guarantee' => 10200, 'purchases' => 18]],
            'castellon' => ['label' => 'Castellon', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 20, 'benefit' => 19040, 'guarantee' => 6800, 'purchases' => 10]],
            'villareal' => ['label' => 'Villareal', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 28, 'benefit' => 27989, 'guarantee' => 9520, 'purchases' => 17]],
            'elche' => ['label' => 'Elche', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 20, 'benefit' => 17612, 'guarantee' => 8500, 'purchases' => 12]],
            'alcoy' => ['label' => 'Alcoy', 'manager_key' => 'nicolas-fernandez', 'objectives' => ['deliveries' => 18, 'benefit' => 17993, 'guarantee' => 7650, 'purchases' => 11]],
            'bilbao' => ['label' => 'Bilbao', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 35, 'benefit' => 30821, 'guarantee' => 11900, 'purchases' => 24]],
            'fontellas' => ['label' => 'Fontellas', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 30, 'benefit' => 28560, 'guarantee' => 12750, 'purchases' => 15]],
            'pamplona' => ['label' => 'Pamplona', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 35, 'benefit' => 33320, 'guarantee' => 11900, 'purchases' => 18]],
            'san-sebastian' => ['label' => 'San Sebastian', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 20, 'benefit' => 19040, 'guarantee' => 8500, 'purchases' => 12]],
            'zaragoza' => ['label' => 'Zaragoza', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 35, 'benefit' => 33320, 'guarantee' => 11900, 'purchases' => 27]],
            'gijon' => ['label' => 'Gijon', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 30, 'benefit' => 28560, 'guarantee' => 12750, 'purchases' => 18]],
            'a-coruna' => ['label' => 'A Coruna', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 28, 'benefit' => 29322, 'guarantee' => 11900, 'purchases' => 17]],
            'valladolid' => ['label' => 'Valladolid', 'manager_key' => 'kosta-plamenov', 'objectives' => ['deliveries' => 29, 'benefit' => 27608, 'guarantee' => 12325, 'purchases' => 17]],
            'badalona' => ['label' => 'Badalona', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 18, 'benefit' => 17136, 'guarantee' => 7650, 'purchases' => 14]],
            'girona' => ['label' => 'Girona', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 25, 'benefit' => 26775, 'guarantee' => 10625, 'purchases' => 24]],
            'lleida' => ['label' => 'Lleida', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 18, 'benefit' => 17136, 'guarantee' => 7650, 'purchases' => 12]],
            'llica-de-valls' => ['label' => 'Llica de Valls', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 18, 'benefit' => 17136, 'guarantee' => 7650, 'purchases' => 14]],
            'manresa' => ['label' => 'Manresa', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 20, 'benefit' => 16660, 'guarantee' => 6800, 'purchases' => 12]],
            'sant-boi' => ['label' => 'Sant Boi', 'manager_key' => 'luis-lopez', 'objectives' => ['deliveries' => 55, 'benefit' => 66759, 'guarantee' => 32725, 'purchases' => 39]],
            'alcala-de-guadaira' => ['label' => 'Alcala de Guadaira', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 30, 'benefit' => 44268, 'guarantee' => 17850, 'purchases' => 18]],
            'malaga' => ['label' => 'Malaga', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 30, 'benefit' => 33558, 'guarantee' => 15300, 'purchases' => 12]],
            'malaga-centro' => ['label' => 'Malaga Centro', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 20, 'benefit' => 22848, 'guarantee' => 10200, 'purchases' => 22]],
            'sevilla' => ['label' => 'Sevilla', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 35, 'benefit' => 39984, 'guarantee' => 14280, 'purchases' => 28]],
            'palma' => ['label' => 'Palma', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 40, 'benefit' => 38080, 'guarantee' => 20400, 'purchases' => 30]],
            'alcobendas' => ['label' => 'Alcobendas', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 25, 'benefit' => 23800, 'guarantee' => 10625, 'purchases' => 20]],
            'rivas' => ['label' => 'Rivas', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 40, 'benefit' => 38080, 'guarantee' => 17000, 'purchases' => 24]],
            'torrejon-de-ardoz' => ['label' => 'Torrejon de Ardoz', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 40, 'benefit' => 38080, 'guarantee' => 13600, 'purchases' => 30]],
            'collado-villalba' => ['label' => 'Collado Villalba', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 25, 'benefit' => 26775, 'guarantee' => 10625, 'purchases' => 17]],
            'dos-hermanas' => ['label' => 'Dos Hermanas', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 18, 'benefit' => 17136, 'guarantee' => 7650, 'purchases' => 11]],
            'badajoz' => ['label' => 'Badajoz', 'manager_key' => 'david-baeza', 'objectives' => ['deliveries' => 15, 'benefit' => 14280, 'guarantee' => 6375, 'purchases' => 9]],
        ],
    ];

    private const EXCLUDED_NORMALIZED_DELEGATIONS = [
        'call fontellas',
        'general',
        'sin zona',
        'sin delegacion',
    ];

    private const NORMALIZED_DELEGATION_ALIASES = [
        'a coruna' => 'A Coruna',
        'san boi' => 'Sant Boi',
        'sant boi' => 'Sant Boi',
        'sant boi de llobregat' => 'Sant Boi',
        'alcala de guadaira' => 'Alcalá de Guadaira',
        'castellon' => 'Castellón',
        'dos hermanas' => 'Dos Hermanas',
        'torrejon' => 'Torrejón de Ardoz',
        'torrejon de ardoz' => 'Torrejón de Ardoz',
        'torrejón' => 'Torrejón de Ardoz',
        'torrejón de ardoz' => 'Torrejón de Ardoz',
        'villalba' => 'Collado Villalba',
        'collado villaba' => 'Collado Villalba',
        'mallorca' => 'Palma',
        'palma' => 'Palma',
        'palma de mallorca' => 'Palma',
        'villareal' => 'Villareal',
        'villarreal' => 'Villareal',
        'villareal almasora' => 'Villareal',
        'villarreal almassora' => 'Villareal',
        'villarreal almasora' => 'Villareal',
        'villareal almassora' => 'Villareal',
        'almassora' => 'Villareal',
        'almasora' => 'Villareal',
        'llica de vall' => 'Llica de Valls',
        'llica' => 'Llica de Valls',
        'llica de vall barcelona' => 'Llica de Valls',
        'lliaa de vall' => 'Llica de Valls',
        'lliaa de valls' => 'Llica de Valls',
        'llica de valls' => 'Llica de Valls',
        'llica de valls barcelona' => 'Llica de Valls',
        'llica de valls bcn' => 'Llica de Valls',
        'malga' => 'Malaga',
        'malaga' => 'Malaga',
    ];

    private const GOOGLE_REVIEWS_LOCATION_BY_DELEGATION = [
        'a coruna' => 'HR Motor || A Coruña',
        'alcala de guadaira' => 'HR Motor || Alcalá de Guadaíra',
        'alcobendas' => 'HR Motor || Alcobendas',
        'alcoy' => 'HR Motor || Alcoy',
        'alicante' => 'HR Motor || Alicante',
        'badalona' => 'HR Motor || Badalona',
        'badajoz' => 'HR Motor || Badajoz',
        'bilbao' => 'HR Motor || Bilbao',
        'castellon' => 'HR Motor || Castellón',
        'collado villalba' => 'HR Motor || Collado Villalba',
        'dos hermanas' => 'HR Motor || Dos Hermanas',
        'elche' => 'HR MOTOR || Elche',
        'gijon' => 'HR Motor || Gijón',
        'girona' => 'HR Motor || Girona',
        'lleida' => 'HR Motor || Lleida',
        'llica de vall' => 'HR Motor || Lliçà de Vall',
        'llica de valls' => 'HR Motor || Lliçà de Vall',
        'malaga' => 'HR Motor || Málaga',
        'malaga centro' => 'HR Motor || Málaga Centro',
        'manresa' => 'HR Motor || Manresa',
        'murcia' => 'HR Motor || Murcia',
        'palma' => 'HR Motor || Palma de Mallorca',
        'pamplona' => 'HR Motor || Pamplona',
        'rivas' => 'HR Motor || Rivas - Vaciamadrid',
        'rivas vaciamadrid' => 'HR Motor || Rivas - Vaciamadrid',
        'san sebastian' => 'HR Motor || San Sebastián',
        'sant boi' => 'HR Motor || Sant Boi de Llobregat',
        'sedavi' => 'HR Motor || Sedaví',
        'sevilla' => 'HR Motor || Sevilla Centro',
        'sevilla centro' => 'HR Motor || Sevilla Centro',
        'torrejon de ardoz' => 'HR Motor || Torrejón de Ardoz',
        'fontellas' => 'HR Motor || Tudela-Fontellas',
        'tudela fontellas' => 'HR Motor || Tudela-Fontellas',
        'valencia' => 'HR Motor || València',
        'paterna' => 'HR Motor || Valencia Paterna',
        'valencia paterna' => 'HR Motor || Valencia Paterna',
        'valladolid' => 'HR Motor || Valladolid',
        'villareal' => 'HR Motor || Villarreal',
        'zaragoza' => 'HR Motor || Zaragoza',
    ];

    private const TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY = 'commercial_commission_temporarily_unlocked_months';

    private const SHARED_DELEGATION_DELIVERY_GOAL_START = '2026-07-01';

    public function defaults(): array
    {
        return [
            'sales' => [
                'solo_delivery_amount' => 60.0,
                'shared_owner_delivery_amount' => 30.0,
                'shared_secondary_delivery_amount' => 30.0,
            ],
            'purchases' => [
                'commission_percent' => 0.018,
            ],
            'stock' => [
                'days_threshold' => 150,
                'amount' => 10.0,
            ],
            'bonus' => [
                'start_after_delivery' => 15,
                'amount_per_delivery' => 30.0,
            ],
            'delivery_brackets' => [
                ['max_deliveries' => 6, 'percent' => 0.0],
                ['max_deliveries' => 11, 'percent' => 0.8],
                ['max_deliveries' => null, 'percent' => 1.0],
            ],
            'penalties' => [
                'guarantee_total_threshold' => 3500.0,
                'guarantee_percent' => 0.10,
                'reviews_low_threshold' => 30.0,
                'reviews_mid_threshold' => 50.0,
                'reviews_low_percent' => 0.50,
                'reviews_mid_percent' => 0.10,
                'financing_percentage_threshold' => 40.0,
                'financing_percent' => 0.10,
            ],
            'financing_product_brackets' => [
                ['min_amount' => 50001.0, 'percent' => 0.09],
                ['min_amount' => 30001.0, 'percent' => 0.08],
                ['min_amount' => 25001.0, 'percent' => 0.07],
                ['min_amount' => 17001.0, 'percent' => 0.06],
                ['min_amount' => 12001.0, 'percent' => 0.05],
                ['min_amount' => 8001.0, 'percent' => 0.04],
                ['min_amount' => 5001.0, 'percent' => 0.03],
                ['min_amount' => 1.0, 'percent' => 0.02],
            ],
            'guarantee_product_brackets' => [
                ['min_amount' => 20401.0, 'percent' => 0.11],
                ['min_amount' => 14401.0, 'percent' => 0.09],
                ['min_amount' => 9601.0, 'percent' => 0.07],
                ['min_amount' => 5401.0, 'percent' => 0.06],
                ['min_amount' => 3501.0, 'percent' => 0.04],
                ['min_amount' => 1.0, 'percent' => 0.03],
            ],
            'delegations' => [
                'goals' => [],
            ],
            'delegation_bonus' => [
                'objective_brackets' => [
                    ['min_percent' => 100.0001, 'percent' => 0.0060],
                    ['min_percent' => 100.0, 'percent' => 0.0050],
                    ['min_percent' => 95.0, 'percent' => 0.0045],
                    ['min_percent' => 90.0, 'percent' => 0.0040],
                    ['min_percent' => 85.0, 'percent' => 0.0035],
                    ['min_percent' => 0.0, 'percent' => 0.0],
                ],
                'profitability_ratio_threshold' => 14.0,
                'profitability_bonus_percent' => 0.10,
                'financed_amount_ratio_threshold' => 40.0,
                'financed_amount_bonus_percent' => 0.10,
            ],
            'area_manager' => [
                'kpi_bases' => [
                    'deliveries' => 150.0,
                    'benefit' => 150.0,
                    'guarantee' => 100.0,
                    'purchases' => 100.0,
                ],
                'zone_keys' => [
                    ['min_percent' => 100.0, 'multiplier' => 1.10],
                    ['min_percent' => 91.0, 'multiplier' => 1.00],
                    ['min_percent' => 85.0, 'multiplier' => 0.90],
                    ['min_percent' => 0.0, 'multiplier' => 0.80],
                ],
                'assignments' => [],
            ],
            'financials' => [
                'financed_percentage_brackets' => [
                    ['min_percent' => 47.0001, 'incentive' => 0.0125],
                    ['min_percent' => 44.1, 'incentive' => 0.0100],
                    ['min_percent' => 42.1, 'incentive' => 0.0075],
                    ['min_percent' => 40.1, 'incentive' => 0.0050],
                    ['min_percent' => 39.1, 'incentive' => 0.0040],
                    ['min_percent' => 38.0, 'incentive' => 0.0020],
                    ['min_percent' => 0.0, 'incentive' => 0.0010],
                ],
                'profitability_brackets' => [
                    ['min_percent' => 16.6, 'incentive' => 0.0075],
                    ['min_percent' => 15.6, 'incentive' => 0.0050],
                    ['min_percent' => 14.5, 'incentive' => 0.0040],
                    ['min_percent' => 14.0, 'incentive' => 0.0020],
                    ['min_percent' => 13.0, 'incentive' => 0.0010],
                    ['min_percent' => 0.0, 'incentive' => 0.0],
                ],
                'guarantee_percentage_brackets' => [
                    ['min_percent' => 7.0, 'incentive' => 0.0050],
                    ['min_percent' => 6.0, 'incentive' => 0.0030],
                    ['min_percent' => 5.0, 'incentive' => 0.0020],
                    ['min_percent' => 0.0, 'incentive' => 0.0],
                ],
                'excluded_interest_rates' => [
                    '3,99%',
                    '4,99%',
                    '5,99%',
                ],
            ],
        ];
    }

    public function resolveSelectedMonth(?string $month): CarbonImmutable
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        }

        return $this->openMonth();
    }

    public function openMonth(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMonth();
    }

    public function isEditableMonth(CarbonImmutable|string $month, ?Request $request = null): bool
    {
        $value = $month instanceof CarbonImmutable
            ? $month->format('Y-m')
            : (string) $month;

        if ($value === $this->openMonth()->format('Y-m')) {
            return true;
        }

        if ($request === null) {
            return false;
        }

        return $this->isTemporarilyUnlocked($request, $value);
    }

    public function forMonth(CarbonImmutable|string $month): array
    {
        $selectedMonth = $month instanceof CarbonImmutable
            ? $month->startOfMonth()
            : CarbonImmutable::createFromFormat('!Y-m', (string) $month)->startOfMonth();
        $monthKey = $selectedMonth->format('Y-m');
        $defaults = $this->defaults();
        $defaults['delegations']['goals'] = $this->inheritedDelegationGoals($selectedMonth);
        $defaults['area_manager']['assignments'] = $this->inheritedAreaManagerAssignments($selectedMonth);
        $defaults = $this->withSpecialFinancialResponsibleRules($defaults, $selectedMonth);

        if (! Schema::hasTable('commercial_commission_month_settings')) {
            return $this->withSharedDelegationDeliveryGoals($this->normalizeSettings($defaults), $selectedMonth);
        }

        $stored = CommercialCommissionMonthSetting::query()
            ->where('month', $monthKey)
            ->first();

        if (! is_array($stored?->settings)) {
            return $this->withSharedDelegationDeliveryGoals($this->normalizeSettings($defaults), $selectedMonth);
        }

        return $this->withSharedDelegationDeliveryGoals($this->withSpecialFinancialResponsibleRules(
            $this->normalizeSettings(array_replace_recursive($defaults, $stored->settings)),
            $selectedMonth
        ), $selectedMonth);
    }

    public function saveForMonth(string $month, array $settings): void
    {
        if ($this->usesSharedDelegationDeliveryGoals($month)) {
            unset($settings['delegations']['goals']);
        }

        CommercialCommissionMonthSetting::query()->updateOrCreate(
            ['month' => $month],
            ['settings' => $this->normalizeSettings($settings)]
        );
    }

    public function usesSharedDelegationDeliveryGoals(CarbonImmutable|string $month): bool
    {
        $selectedMonth = $month instanceof CarbonImmutable
            ? $month->startOfMonth()
            : CarbonImmutable::createFromFormat('!Y-m', (string) $month)->startOfMonth();

        return $selectedMonth->greaterThanOrEqualTo(CarbonImmutable::parse(self::SHARED_DELEGATION_DELIVERY_GOAL_START));
    }

    public function availableDelegations(array $settings = []): array
    {
        $delegations = [];

        if (
            Schema::hasTable('salesforce_opportunities')
            && (
                Schema::hasColumn('salesforce_opportunities', 'delivery_store')
                || Schema::hasColumn('salesforce_opportunities', 'owner_delegation')
            )
        ) {
            $storedDelegations = SalesforceOpportunity::query()
                ->get(['delivery_store', 'owner_delegation']);

            foreach ($storedDelegations as $opportunity) {
                $label = $this->deliveryDelegationLabel(
                    $opportunity->delivery_store,
                    $opportunity->owner_delegation
                );

                if (! $this->shouldIncludeDelegationLabel($label)) {
                    continue;
                }

                $delegations[$this->delegationKey($label)] = $label;
            }
        }

        foreach (($settings['delegations']['goals'] ?? []) as $goalKey => $goal) {
            $label = $this->normalizeDelegationLabel($goal['label'] ?? $goalKey);

            if (! $this->shouldIncludeDelegationLabel($label)) {
                continue;
            }

            $delegations[$this->delegationKey($label)] = $label;
        }

        return collect($delegations)
            ->sortBy(fn (string $label) => Str::of($label)->ascii()->lower()->toString())
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    public function areaManagerDefinitions(): array
    {
        return collect(self::AREA_MANAGER_DEFINITIONS)
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    public function availableAreaManagerDelegations(array $settings = []): array
    {
        $delegations = collect($this->availableDelegations($settings))
            ->pluck('label', 'key')
            ->all();

        foreach (($settings['area_manager']['assignments'] ?? []) as $assignmentKey => $assignment) {
            $label = $this->normalizeDelegationLabel($assignment['label'] ?? $assignmentKey);

            if (! $this->shouldIncludeDelegationLabel($label)) {
                continue;
            }

            $delegations[$this->delegationKey($label)] = $label;
        }

        return collect($delegations)
            ->sortBy(fn (string $label) => Str::of($label)->ascii()->lower()->toString())
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    public function delegationKey(string $value): string
    {
        return Str::of($this->normalizeDelegationLabel($value))
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->toString();
    }

    public function shouldIncludeDelegationLabel(?string $value): bool
    {
        $label = $this->normalizeDelegationLabel($value);

        if ($label === '') {
            return false;
        }

        $normalized = Str::of($label)
            ->ascii()
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        if (in_array($normalized, self::EXCLUDED_NORMALIZED_DELEGATIONS, true)) {
            return false;
        }

        return ! str_ends_with($normalized, ' general');
    }

    public function deliveryDelegationLabel(?string $deliveryStore, ?string $ownerDelegation = null): string
    {
        $deliveryStoreLabel = $this->normalizeDelegationLabel($deliveryStore);

        if ($deliveryStoreLabel !== '') {
            return $deliveryStoreLabel;
        }

        return $this->normalizeDelegationLabel($ownerDelegation);
    }

    public function googleReviewsLocationForDelegation(string $delegationLabel): ?string
    {
        $normalized = $this->normalizedDelegationComparable(
            $this->normalizeDelegationLabel($delegationLabel)
        );

        return self::GOOGLE_REVIEWS_LOCATION_BY_DELEGATION[$normalized] ?? null;
    }

    public function normalizeDelegationLabel(mixed $value): string
    {
        $label = trim((string) $value);

        if ($label === '') {
            return '';
        }

        $label = preg_replace('/[_\/\\\\-]+/u', ' ', $label) ?? $label;
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
        $label = preg_replace('/^hr\s*motor\s+/iu', '', $label) ?? $label;
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);

        if ($label === '') {
            return '';
        }

        $comparable = $this->normalizedDelegationComparable($label);

        if (in_array($comparable, self::EXCLUDED_NORMALIZED_DELEGATIONS, true)) {
            return '';
        }

        if (array_key_exists($comparable, self::NORMALIZED_DELEGATION_ALIASES)) {
            return self::NORMALIZED_DELEGATION_ALIASES[$comparable];
        }

        if (Str::upper($label) === $label) {
            return Str::of(Str::lower($label))
                ->headline()
                ->trim()
                ->toString();
        }

        return $label;
    }

    private function normalizedDelegationComparable(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    private function normalizeSettings(array $settings): array
    {
        $goals = [];

        foreach (($settings['delegations']['goals'] ?? []) as $goalKey => $goal) {
            $label = $this->normalizeDelegationLabel($goal['label'] ?? $goalKey);
            $key = $this->delegationKey($label);

            if ($key === '' || ! $this->shouldIncludeDelegationLabel($label)) {
                continue;
            }

            $candidate = [
                'label' => $label,
                'target_deliveries' => max(0, (int) ($goal['target_deliveries'] ?? 0)),
            ];

            if (! array_key_exists($key, $goals) || $candidate['target_deliveries'] > 0) {
                $goals[$key] = $candidate;
            }
        }

        $settings['delegations']['goals'] = $goals;

        $settings['area_manager']['kpi_bases'] = [
            'deliveries' => max(0, (float) ($settings['area_manager']['kpi_bases']['deliveries'] ?? 150)),
            'benefit' => max(0, (float) ($settings['area_manager']['kpi_bases']['benefit'] ?? 150)),
            'guarantee' => max(0, (float) ($settings['area_manager']['kpi_bases']['guarantee'] ?? 100)),
            'purchases' => max(0, (float) ($settings['area_manager']['kpi_bases']['purchases'] ?? 100)),
        ];

        $settings['area_manager']['zone_keys'] = collect($settings['area_manager']['zone_keys'] ?? [])
            ->map(fn (array $bracket) => [
                'min_percent' => max(0, (float) ($bracket['min_percent'] ?? 0)),
                'multiplier' => max(0, (float) ($bracket['multiplier'] ?? 0)),
            ])
            ->sortByDesc('min_percent')
            ->values()
            ->all();

        $assignments = [];

        foreach (($settings['area_manager']['assignments'] ?? []) as $assignmentKey => $assignment) {
            $label = $this->normalizeDelegationLabel($assignment['label'] ?? $assignmentKey);
            $key = $this->delegationKey($label);

            if ($key === '' || ! $this->shouldIncludeDelegationLabel($label)) {
                continue;
            }

            $managerKey = (string) ($assignment['manager_key'] ?? '');

            if ($managerKey !== '' && ! array_key_exists($managerKey, self::AREA_MANAGER_DEFINITIONS)) {
                $managerKey = '';
            }

            $candidate = [
                'label' => $label,
                'manager_key' => $managerKey,
                'active' => (bool) ($assignment['active'] ?? true),
                'objectives' => [
                    'deliveries' => max(0, (float) ($assignment['objectives']['deliveries'] ?? 0)),
                    'benefit' => max(0, (float) ($assignment['objectives']['benefit'] ?? 0)),
                    'guarantee' => max(0, (float) ($assignment['objectives']['guarantee'] ?? 0)),
                    'purchases' => max(0, (float) ($assignment['objectives']['purchases'] ?? 0)),
                ],
            ];

            // Accents and case must not create a second area-manager centre.
            // Keep the configured entry that actually carries the monthly goals.
            if (
                ! array_key_exists($key, $assignments)
                || $this->areaManagerAssignmentObjectiveTotal($candidate) >= $this->areaManagerAssignmentObjectiveTotal($assignments[$key])
            ) {
                $assignments[$key] = $candidate;
            }
        }

        $settings['area_manager']['assignments'] = collect($assignments)
            ->sortBy(fn (array $assignment) => $assignment['label'])
            ->all();

        $financialDefaults = $this->defaults()['financials'];
        $settings['financials']['financed_percentage_brackets'] = $this->normalizeFinancialBrackets(
            $settings['financials']['financed_percentage_brackets'] ?? [],
            $financialDefaults['financed_percentage_brackets']
        );
        $settings['financials']['profitability_brackets'] = $this->normalizeFinancialBrackets(
            $settings['financials']['profitability_brackets'] ?? [],
            $financialDefaults['profitability_brackets']
        );
        $settings['financials']['guarantee_percentage_brackets'] = $this->normalizeFinancialBrackets(
            $settings['financials']['guarantee_percentage_brackets'] ?? [],
            $financialDefaults['guarantee_percentage_brackets']
        );
        $settings['financials']['excluded_interest_rates'] = collect($settings['financials']['excluded_interest_rates'] ?? [])
            ->map(fn (mixed $value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();
        unset(
            $settings['financials']['special_zone_net_commission_percentages'],
            $settings['financials']['special_user_net_commission_percentages']
        );

        return $settings;
    }

    private function withSharedDelegationDeliveryGoals(array $settings, CarbonImmutable $month): array
    {
        if (! $this->usesSharedDelegationDeliveryGoals($month)) {
            return $settings;
        }

        $settings['delegations']['goals'] = collect($settings['area_manager']['assignments'] ?? [])
            ->mapWithKeys(function (array $assignment, string $key): array {
                $label = $this->normalizeDelegationLabel($assignment['label'] ?? $key);
                $delegationKey = $this->delegationKey($label);

                if ($delegationKey === '' || ! $this->shouldIncludeDelegationLabel($label)) {
                    return [];
                }

                return [$delegationKey => [
                    'label' => $label,
                    'target_deliveries' => max(0, (int) round((float) data_get($assignment, 'objectives.deliveries', 0))),
                ]];
            })
            ->sortBy('label')
            ->all();

        return $settings;
    }

    private function withSpecialFinancialResponsibleRules(array $settings, CarbonImmutable $month): array
    {
        $rules = collect(config('commercial_commissions.financial_special_responsible_net_commission_percentages', []))
            ->mapWithKeys(function (mixed $rule, mixed $responsibleKey) use ($month): array {
                $key = trim((string) $responsibleKey);
                $effectiveFrom = trim((string) data_get($rule, 'effective_from'));

                if ($key === '' || $effectiveFrom === '' || $month->lessThan(CarbonImmutable::parse($effectiveFrom)->startOfMonth())) {
                    return [];
                }

                return [$key => [
                    'label' => trim((string) data_get($rule, 'label')),
                    'zone_name' => trim((string) data_get($rule, 'zone_name')),
                    'percent' => max(0, min(1, (float) data_get($rule, 'percent', 0))),
                ]];
            })
            ->filter(fn (array $rule): bool => $rule['percent'] > 0 && $rule['zone_name'] !== '')
            ->all();

        unset(
            $settings['financials']['special_zone_net_commission_percentages'],
            $settings['financials']['special_user_net_commission_percentages']
        );
        $settings['financials']['special_responsible_net_commission_percentages'] = $rules;

        return $settings;
    }

    private function areaManagerAssignmentObjectiveTotal(array $assignment): float
    {
        return (float) collect($assignment['objectives'] ?? [])->sum(
            fn (mixed $objective): float => max(0, (float) $objective)
        );
    }

    public function canTemporarilyUnlockMonth(CarbonImmutable|string $month): bool
    {
        $selectedMonth = $month instanceof CarbonImmutable
            ? $month->startOfMonth()
            : CarbonImmutable::createFromFormat('!Y-m', (string) $month)->startOfMonth();

        return $selectedMonth->lessThan($this->openMonth()->startOfMonth());
    }

    public function unlockMonth(Request $request, CarbonImmutable|string $month): bool
    {
        $value = $month instanceof CarbonImmutable
            ? $month->format('Y-m')
            : (string) $month;

        if (! $this->canTemporarilyUnlockMonth($value)) {
            return false;
        }

        $months = collect($request->session()->get(self::TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY, []))
            ->push($value)
            ->filter(fn (mixed $item) => is_string($item) && preg_match('/^\d{4}-\d{2}$/', $item) === 1)
            ->unique()
            ->values()
            ->all();

        $request->session()->put(self::TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY, $months);

        return true;
    }

    public function closeMonth(Request $request, CarbonImmutable|string $month): void
    {
        $value = $month instanceof CarbonImmutable
            ? $month->format('Y-m')
            : (string) $month;

        $months = collect($request->session()->get(self::TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY, []))
            ->reject(fn (mixed $item) => (string) $item === $value)
            ->values()
            ->all();

        if ($months === []) {
            $request->session()->forget(self::TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY);

            return;
        }

        $request->session()->put(self::TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY, $months);
    }

    public function isTemporarilyUnlocked(Request $request, CarbonImmutable|string $month): bool
    {
        $value = $month instanceof CarbonImmutable
            ? $month->format('Y-m')
            : (string) $month;

        return collect($request->session()->get(self::TEMPORARY_UNLOCKED_MONTHS_SESSION_KEY, []))
            ->contains(fn (mixed $item) => (string) $item === $value);
    }

    private function inheritedDelegationGoals(CarbonImmutable $selectedMonth): array
    {
        if (! Schema::hasTable('commercial_commission_month_settings')) {
            return [];
        }

        $cursor = $selectedMonth->subMonthNoOverflow()->startOfMonth();
        $oldestMonth = CarbonImmutable::parse('2020-01-01')->startOfMonth();

        while ($cursor->greaterThanOrEqualTo($oldestMonth)) {
            $stored = CommercialCommissionMonthSetting::query()
                ->where('month', $cursor->format('Y-m'))
                ->first();

            if (is_array($stored?->settings) && ! empty($stored->settings['delegations']['goals'])) {
                return $this->normalizeSettings([
                    'delegations' => [
                        'goals' => $stored->settings['delegations']['goals'],
                    ],
                ])['delegations']['goals'] ?? [];
            }

            $cursor = $cursor->subMonthNoOverflow()->startOfMonth();
        }

        return [];
    }

    private function inheritedAreaManagerAssignments(CarbonImmutable $selectedMonth): array
    {
        if (Schema::hasTable('commercial_commission_month_settings')) {
            $cursor = $selectedMonth->subMonthNoOverflow()->startOfMonth();
            $oldestMonth = CarbonImmutable::parse('2020-01-01')->startOfMonth();

            while ($cursor->greaterThanOrEqualTo($oldestMonth)) {
                $stored = CommercialCommissionMonthSetting::query()
                    ->where('month', $cursor->format('Y-m'))
                    ->first();

                if (is_array($stored?->settings) && ! empty($stored->settings['area_manager']['assignments'])) {
                    return $this->normalizeSettings([
                        'area_manager' => [
                            'assignments' => $stored->settings['area_manager']['assignments'],
                        ],
                    ])['area_manager']['assignments'] ?? [];
                }

                $cursor = $cursor->subMonthNoOverflow()->startOfMonth();
            }
        }

        $cursor = $selectedMonth->startOfMonth();
        $oldestMonth = CarbonImmutable::parse('2020-01-01')->startOfMonth();

        while ($cursor->greaterThanOrEqualTo($oldestMonth)) {
            $bootstrap = self::AREA_MANAGER_BOOTSTRAP_ASSIGNMENTS[$cursor->format('Y-m')] ?? null;

            if (is_array($bootstrap) && $bootstrap !== []) {
                return $this->normalizeSettings([
                    'area_manager' => [
                        'assignments' => $bootstrap,
                    ],
                ])['area_manager']['assignments'] ?? [];
            }

            $cursor = $cursor->subMonthNoOverflow()->startOfMonth();
        }

        return [];
    }

    private function normalizeFinancialBrackets(array $brackets, array $defaults): array
    {
        $normalized = collect($brackets)
            ->map(fn (array $row) => [
                'min_percent' => max(0, (float) ($row['min_percent'] ?? 0)),
                'incentive' => max(0, (float) ($row['incentive'] ?? 0)),
            ])
            ->sortByDesc('min_percent')
            ->values()
            ->all();

        // Preserve the newly confirmed 13%-13.99% profitability band in
        // configurations saved before the band existed.
        if (
            collect($defaults)->contains(fn (array $row): bool => (float) ($row['min_percent'] ?? -1) === 13.0)
            && ! collect($normalized)->contains(fn (array $row): bool => (float) ($row['min_percent'] ?? -1) === 13.0)
        ) {
            $normalized[] = ['min_percent' => 13.0, 'incentive' => 0.0010];
            $normalized = collect($normalized)->sortByDesc('min_percent')->values()->all();
        }

        return $normalized === [] ? $defaults : $normalized;
    }
}
