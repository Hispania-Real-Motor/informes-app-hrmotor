<?php

namespace App\Services\Reports\Leads;

use Illuminate\Support\Str;

class LeadDelegationNormalizer
{
    public const UNCLASSIFIED = 'Sin clasificar';

    public const NO_GROUP = 'Sin grupo';

    public const INDEPENDENT_GROUP = 'Independientes';

    private ?array $aliasMap = null;

    private ?array $delegationMeta = null;

    private array $normalizationCache = [];

    public function normalize(?string $raw): array
    {
        $raw = $this->clean($raw);

        $cacheKey = $raw ?? '';

        if (array_key_exists($cacheKey, $this->normalizationCache)) {
            return $this->normalizationCache[$cacheKey];
        }

        if ($raw === null) {
            return $this->normalizationCache[$cacheKey] = $this->unclassified(null);
        }

        $match = $this->aliasMap()[$this->key($raw)] ?? null;

        if ($match === null) {
            return $this->normalizationCache[$cacheKey] = [
                'raw' => $raw,
                'delegation' => self::UNCLASSIFIED,
                'group' => self::NO_GROUP,
                'zone' => self::UNCLASSIFIED,
                'is_classified' => false,
                'raw_unmapped' => $raw,
            ];
        }

        $meta = $this->delegationMeta()[$match['delegation']] ?? [
            'group' => self::NO_GROUP,
            'zone' => self::UNCLASSIFIED,
        ];

        return $this->normalizationCache[$cacheKey] = [
            'raw' => $raw,
            'delegation' => $match['delegation'],
            'group' => $meta['group'],
            'zone' => $meta['zone'],
            'is_classified' => true,
            'raw_unmapped' => null,
        ];
    }

    public function sortLabels(iterable $labels): array
    {
        return collect($labels)
            ->filter()
            ->unique()
            ->sortBy(fn (string $label) => $label === self::UNCLASSIFIED ? 'zzzzzz' : Str::ascii($label))
            ->values()
            ->all();
    }

    public function knownZones(): array
    {
        return [
            'Zona Norte',
            'Zona Cataluña',
            'Zona Mediterraneo',
            'Zona Sur y Centro',
        ];
    }

    public function knownGroups(): array
    {
        return [
            'Grupo Madrid',
            'Grupo Barcelona',
            'Grupo Málaga',
            'Grupo Sevilla',
            'Grupo Valencia',
            'Grupo Castellón',
            'Grupo Navarra',
            'Grupo País Vasco',
            self::INDEPENDENT_GROUP,
        ];
    }

    private function aliasMap(): array
    {
        if ($this->aliasMap !== null) {
            return $this->aliasMap;
        }

        $map = [];

        $this->add($map, 'Torrejón', [
            'HR MOTOR TORREJON', 'Torrejón', 'Torrejon', 'Torrejón de Ardoz', 'leadstorrejon@hrmotor.com',
        ]);
        $this->add($map, 'Villalba', [
            'HR MOTOR VILLALBA', 'HR MOTOR COLLADO VILLABA', 'HR MOTOR COLLADO VILLALBA', 'Villalba',
            'Collado Villalba', 'leadsvillalba@hrmotor.com',
        ]);
        $this->add($map, 'Rivas', [
            'HR MOTOR RIVAS-VACIA MADRID', 'HR MOTOR RIVAS-VACIAMADRID', 'HR MOTOR RIVAS', 'Rivas', 'Rivas-Vaciamadrid',
            'Rivas vacia madrid', 'leadsrivas@hrmotor.com',
        ]);
        $this->add($map, 'Alcobendas', [
            'HR MOTOR ALCOBENDAS', 'Alcobendas', 'leadsalcobendas@hrmotor.com',
        ]);
        $this->add($map, 'Madrid General', [
            'Madrid', 'HR MOTOR MADRID', 'Zona Madrid', 'leadsmadrid@hrmotor.com', 'leadsmadridgeneral@hrmotor.com',
        ]);

        $this->add($map, 'Sant Boi', [
            'HR MOTOR SANT BOI DE LLOBREGAT', 'HR MOTOR SAN BOI', 'HR MOTOR SANT BOI', 'Sant Boi',
            'San Boi', 'Sant Boi de Llobregat', 'sant boi de llobregat',
        ]);
        $this->add($map, 'Badalona', ['HR MOTOR BADALONA', 'Badalona', 'leadsbadalona@hrmotor.com']);
        $this->add($map, 'Lliçà de Vall', [
            'HR MOTOR LLIÇÀ DE VALL', 'HR MOTOR LLIÇA', 'Lliçà de Vall', 'Lliça de Vall',
            'Llica de Vall', 'LLIÇÀ DE VALL', 'HR MOTOR LLIÇÀ DE VALLS', 'HR MOTOR LLIÇA DE VALLS',
            'HR MOTOR LLICA DE VALLS', 'Lliçà de Valls', 'Lliça de Valls', 'Llica de Valls',
        ]);
        $this->add($map, 'Manresa', ['HR MOTOR MANRESA', 'Manresa', 'leadsmanresa@hrmotor.com']);
        $this->add($map, 'Lleida', ['HR MOTOR LLEIDA', 'Lleida', 'Lerida', 'leadslleida@hrmotor.com']);
        $this->add($map, 'Girona', ['HR MOTOR GIRONA', 'Girona', 'leadsgirona@hrmotor.com']);
        $this->add($map, 'Barcelona General', [
            'Barcelona', 'Zona Barcelona', 'leadsbarcelona@hrmotor.com', 'Hr Motor Barcelona',
            'HR MOTOR BARCELONA', 'leadssantboi@hrmotor.com', 'leadsllica@hrmotor.com',
        ]);

        $this->add($map, 'Valencia', ['HR MOTOR VALENCIA', 'HR MOTOR CAMPA VALENCIA', 'Valencia']);
        $this->add($map, 'Valencia General', ['Valencia general', 'leadsvalencia@hrmotor.com']);
        $this->add($map, 'Paterna', ['HR MOTOR PATERNA', 'Paterna', 'Valencia Paterna', 'leadspaterna@hrmotor.com']);
        $this->add($map, 'Sedaví', ['HR MOTOR SEDAVI', 'Sedaví', 'Sedavi', 'Valencia Sedavi', 'leadssedavi@hrmotor.com']);

        $this->add($map, 'Castellón', ['HR MOTOR CASTELLON', 'Castellón', 'Castellon']);
        $this->add($map, 'Villarreal/Almassora', [
            'HR MOTOR VILLAREAL/ALMASSORA', 'HR MOTOR VILLARREAL/ALMASSORA', 'HR MOTOR ALMAZORAS',
            'HR MOTOR VILLARREAL', 'HR MOTOR VILLAREAL', 'Villarreal', 'Villareal', 'Almassora',
            'leadsvillareal@hrmotor.com',
        ]);
        $this->add($map, 'Castellón General', ['Castellón general', 'Castellon general', 'leadscastellon@hrmotor.com']);

        $this->add($map, 'Sevilla', ['HR MOTOR SEVILLA', 'Sevilla', 'Sevilla Centro', 'HR MOTOR SEVILLA CENTRO']);
        $this->add($map, 'Alcalá de Guadaira', [
            'HR MOTOR ALCALA DE GUADAIRA', 'Sevilla-Alcalá de Guadaira-HR Motor',
            'Alcalá de Guadaira', 'Alcala de Guadaira', 'Alcalá de Guadaíra', 'leadsalcala@hrmotor.com',
        ]);
        $this->add($map, 'Dos Hermanas', ['HR MOTOR DOS HERMANAS', 'Dos Hermanas', 'leadsdoshermanas@hrmotor.com']);
        $this->add($map, 'Sevilla General', ['Sevilla general', 'leadssevilla@hrmotor.com']);

        $this->add($map, 'Fontellas', ['HR MOTOR FONTELLAS', 'HR MOTOR TUDELA', 'Fontellas', 'Tudela', 'leadsfontellas@hrmotor.com']);
        $this->add($map, 'Pamplona', ['HR MOTOR PAMPLONA', 'Pamplona', 'leadspamplona@hrmotor.com']);
        $this->add($map, 'Navarra General', ['Navarra', 'Zona Navarra', 'leadstudela@hrmotor.com']);

        $this->add($map, 'Málaga', ['HR MOTOR MALAGA', 'Málaga', 'Malaga', 'Malga', 'leadsmalaga@hrmotor.com']);
        $this->add($map, 'Málaga Centro', ['HR MOTOR MALAGA CENTRO', 'Málaga Centro', 'Malaga Centro']);
        $this->add($map, 'Málaga General', [
            'Málaga general', 'Malaga general', 'Zona Málaga', 'Zona Malaga', 'leads.gasset@hrmotor.com',
            'leadsalmachar@hrmotor.com',
        ]);

        $this->add($map, 'Alicante', ['HR MOTOR ALICANTE', 'Alicante', 'leadsalicante@hrmotor.com']);
        $this->add($map, 'Murcia', ['HR MOTOR MURCIA', 'Murcia', 'leadsmurcia@hrmotor.com']);
        $this->add($map, 'Zaragoza', ['HR MOTOR ZARAGOZA', 'Zaragoza', 'leadszaragoza@hrmotor.com']);
        $this->add($map, 'Gijón', ['HR MOTOR GIJON', 'Gijón', 'Gijon', 'leadsgijon@hrmotor.com']);
        $this->add($map, 'Valladolid', ['HR MOTOR VALLADOLID', 'Valladolid', 'leadsvalladolid@hrmotor.com']);
        $this->add($map, 'Mallorca', [
            'HR MOTOR MALLORCA', 'Mallorca', 'Palma', 'Palma de Mallorca', 'HR MOTOR PALMA',
            'leads.mallorca@hrmotor.com',
        ]);
        $this->add($map, 'Badajoz', ['HR MOTOR BADAJOZ', 'Badajoz']);
        $this->add($map, 'Elche', ['HR MOTOR ELCHE', 'Elche', 'leadselche@hrmotor.com']);
        $this->add($map, 'A Coruña', [
            'HR MOTOR A CORUÑA', 'HR A CORUÑA', 'HRMOTOR CORUÑA', 'A Coruña', 'Coruña',
            'A Coruna', 'Coruna', 'leadsacoruna@hrmotor.com',
        ]);
        $this->add($map, 'Alcoy', ['HR MOTOR ALCOY', 'Alcoy', 'leadsalcoy@hrmotor.com']);
        $this->add($map, 'Bilbao', ['HR MOTOR BILBAO', 'Bilbao', 'leadsbilbao@hrmotor.com', 'leadsguipuzcoa@hrmotor.com']);
        $this->add($map, 'San Sebastián', ['HR MOTOR SAN SEBASTIAN', 'San Sebastián', 'San Sebastian', 'leadsoiartzun@hrmotor.com']);

        return $this->aliasMap = $map;
    }

    private function delegationMeta(): array
    {
        if ($this->delegationMeta !== null) {
            return $this->delegationMeta;
        }

        $meta = [];
        $this->addMeta($meta, 'Grupo Madrid', 'Zona Sur y Centro', ['Torrejón', 'Villalba', 'Rivas', 'Alcobendas', 'Madrid General']);
        $this->addMeta($meta, 'Grupo Barcelona', 'Zona Cataluña', ['Sant Boi', 'Badalona', 'Lliçà de Vall', 'Manresa', 'Lleida', 'Girona', 'Barcelona General']);
        $this->addMeta($meta, 'Grupo Málaga', 'Zona Sur y Centro', ['Málaga', 'Málaga Centro', 'Málaga General']);
        $this->addMeta($meta, 'Grupo Sevilla', 'Zona Sur y Centro', ['Sevilla', 'Alcalá de Guadaira', 'Dos Hermanas', 'Sevilla General']);
        $this->addMeta($meta, 'Grupo Valencia', 'Zona Mediterraneo', ['Valencia', 'Paterna', 'Sedaví', 'Valencia General']);
        $this->addMeta($meta, 'Grupo Castellón', 'Zona Mediterraneo', ['Castellón', 'Villarreal/Almassora', 'Castellón General']);
        $this->addMeta($meta, 'Grupo Navarra', 'Zona Norte', ['Fontellas', 'Pamplona', 'Navarra General']);
        $this->addMeta($meta, 'Grupo País Vasco', 'Zona Norte', ['Bilbao', 'San Sebastián']);
        $this->addMeta($meta, self::INDEPENDENT_GROUP, 'Zona Mediterraneo', ['Alicante', 'Murcia', 'Elche', 'Alcoy']);
        $this->addMeta($meta, self::INDEPENDENT_GROUP, 'Zona Norte', ['Gijón', 'Zaragoza', 'Valladolid', 'A Coruña']);
        $this->addMeta($meta, self::INDEPENDENT_GROUP, 'Zona Sur y Centro', ['Mallorca', 'Badajoz']);

        return $this->delegationMeta = $meta;
    }

    private function addMeta(array &$meta, string $group, string $zone, array $delegations): void
    {
        foreach ($delegations as $delegation) {
            $meta[$delegation] = [
                'group' => $group,
                'zone' => $zone,
            ];
        }
    }

    private function add(array &$map, string $delegation, array $aliases): void
    {
        foreach ($aliases as $alias) {
            $map[$this->key($alias)] = [
                'delegation' => $delegation,
            ];
        }
    }

    private function unclassified(?string $raw): array
    {
        return [
            'raw' => $raw,
            'delegation' => self::UNCLASSIFIED,
            'group' => self::NO_GROUP,
            'zone' => self::UNCLASSIFIED,
            'is_classified' => false,
            'raw_unmapped' => $raw,
        ];
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function key(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, '@')) {
            return Str::of($value)
                ->lower()
                ->replaceMatches('/\s+/', '')
                ->toString();
        }

        return Str::of($value)
            ->lower()
            ->ascii()
            ->replace(['.', ',', '_'], [' ', ' ', ' '])
            ->replace(['-', '/', '\\'], [' ', ' ', ' '])
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
}
