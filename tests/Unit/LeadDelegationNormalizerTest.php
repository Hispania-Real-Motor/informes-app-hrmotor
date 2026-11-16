<?php

namespace Tests\Unit;

use App\Services\Reports\Leads\LeadDelegationNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadDelegationNormalizerTest extends TestCase
{
    #[DataProvider('mappedDelegations')]
    public function test_normaliza_alias_a_delegacion_grupo_y_zona(string $raw, string $delegation, string $group, string $zone): void
    {
        $result = app(LeadDelegationNormalizer::class)->normalize($raw);

        $this->assertSame($delegation, $result['delegation']);
        $this->assertSame($group, $result['group']);
        $this->assertSame($zone, $result['zone']);
        $this->assertTrue($result['is_classified']);
        $this->assertNull($result['raw_unmapped']);
    }

    #[DataProvider('unmappedDelegations')]
    public function test_valores_no_mapeados_van_a_sin_clasificar(?string $raw): void
    {
        $result = app(LeadDelegationNormalizer::class)->normalize($raw);

        $this->assertSame('Sin clasificar', $result['delegation']);
        $this->assertSame('Sin grupo', $result['group']);
        $this->assertSame('Sin clasificar', $result['zone']);
        $this->assertFalse($result['is_classified']);
    }

    public static function mappedDelegations(): array
    {
        return [
            'torrejon' => ['HR MOTOR TORREJON', 'Torrejón', 'Grupo Madrid', 'Zona Sur y Centro'],
            'torrejon ardoz' => ['Torrejón de Ardoz', 'Torrejón', 'Grupo Madrid', 'Zona Sur y Centro'],
            'madrid email' => ['leadsmadrid@hrmotor.com', 'Madrid General', 'Grupo Madrid', 'Zona Sur y Centro'],
            'rivas' => ['Rivas-Vaciamadrid', 'Rivas', 'Grupo Madrid', 'Zona Sur y Centro'],
            'rivas salesforce current' => ['HR Motor Rivas-Vaciamadrid', 'Rivas', 'Grupo Madrid', 'Zona Sur y Centro'],
            'alcobendas' => ['Alcobendas', 'Alcobendas', 'Grupo Madrid', 'Zona Sur y Centro'],
            'sant boi' => ['Sant Boi de Llobregat', 'Sant Boi', 'Grupo Barcelona', 'Zona Cataluña'],
            'llica' => ['Llica de Vall', 'Lliçà de Vall', 'Grupo Barcelona', 'Zona Cataluña'],
            'llica plural' => ['HR MOTOR LLIÇA DE VALLS', 'Lliçà de Vall', 'Grupo Barcelona', 'Zona Cataluña'],
            'barcelona email' => ['leadsbarcelona@hrmotor.com', 'Barcelona General', 'Grupo Barcelona', 'Zona Cataluña'],
            'sant boi email' => ['leadssantboi@hrmotor.com', 'Barcelona General', 'Grupo Barcelona', 'Zona Cataluña'],
            'sedavi' => ['Valencia Sedavi', 'Sedaví', 'Grupo Valencia', 'Zona Mediterraneo'],
            'paterna' => ['Valencia Paterna', 'Paterna', 'Grupo Valencia', 'Zona Mediterraneo'],
            'valencia email' => ['leadsvalencia@hrmotor.com', 'Valencia General', 'Grupo Valencia', 'Zona Mediterraneo'],
            'villareal' => ['Villareal', 'Villarreal/Almassora', 'Grupo Castellón', 'Zona Mediterraneo'],
            'villarreal salesforce current' => ['HR Motor Villarreal', 'Villarreal/Almassora', 'Grupo Castellón', 'Zona Mediterraneo'],
            'castellon email' => ['leadscastellon@hrmotor.com', 'Castellón General', 'Grupo Castellón', 'Zona Mediterraneo'],
            'sevilla centro' => ['Sevilla Centro', 'Sevilla', 'Grupo Sevilla', 'Zona Sur y Centro'],
            'alcala' => ['Alcalá de Guadaíra', 'Alcalá de Guadaira', 'Grupo Sevilla', 'Zona Sur y Centro'],
            'sevilla email' => ['leadssevilla@hrmotor.com', 'Sevilla General', 'Grupo Sevilla', 'Zona Sur y Centro'],
            'tudela' => ['Tudela', 'Fontellas', 'Grupo Navarra', 'Zona Norte'],
            'tudela email' => ['leadstudela@hrmotor.com', 'Navarra General', 'Grupo Navarra', 'Zona Norte'],
            'malaga centro' => ['Malaga Centro', 'Málaga Centro', 'Grupo Málaga', 'Zona Sur y Centro'],
            'malga' => ['Malga', 'Málaga', 'Grupo Málaga', 'Zona Sur y Centro'],
            'palma' => ['Palma', 'Mallorca', 'Independientes', 'Zona Sur y Centro'],
            'badajoz' => ['HR MOTOR BADAJOZ', 'Badajoz', 'Independientes', 'Zona Sur y Centro'],
            'gasset email' => ['leads.gasset@hrmotor.com', 'Málaga General', 'Grupo Málaga', 'Zona Sur y Centro'],
            'gijon' => ['Gijon', 'Gijón', 'Independientes', 'Zona Norte'],
            'alicante' => ['Alicante', 'Alicante', 'Independientes', 'Zona Mediterraneo'],
            'elche email' => ['leadselche@hrmotor.com', 'Elche', 'Independientes', 'Zona Mediterraneo'],
            'coruna email' => ['leadsacoruna@hrmotor.com', 'A Coruña', 'Independientes', 'Zona Norte'],
            'guipuzcoa email' => ['leadsguipuzcoa@hrmotor.com', 'Bilbao', 'Grupo País Vasco', 'Zona Norte'],
            'san sebastian' => ['San Sebastian', 'San Sebastián', 'Grupo País Vasco', 'Zona Norte'],
        ];
    }

    public static function unmappedDelegations(): array
    {
        return [
            'web alicante' => ['Web Alicante'],
            'llamada directa' => ['Llamada directa'],
            'empty' => [''],
            'null' => [null],
        ];
    }
}
