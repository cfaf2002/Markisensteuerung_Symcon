<?php

declare(strict_types=1);

/**
 * Markisensteuerung – IP-Symcon-Modul für die automatische Markisensteuerung
 *
 * @author    Armin Frohwerk
 * @copyright 2026 Armin Frohwerk
 * @license   MIT – siehe Datei LICENSE im Hauptverzeichnis
 *
 * SPDX-License-Identifier: MIT
 */

/**
 * Sonnenstand (Azimut und Höhe) aus dem Standort von Symcon.
 * Rechnet lokal, ohne Internet und ohne fremde Variablen.
 */
trait MarkiseSunTrait
{
    private static string $locationGuid = '{45E97A63-F870-408A-B259-2933F7EABF74}';

    /** Zwischenspeicher je Aufruf: [Minute, Ergebnis] */
    private ?array $sunCache = null;

    /**
     * Sonnenstand für den Zeitpunkt; null, wenn kein Standort hinterlegt ist.
     *
     * @return array{azimuth: float, elevation: float}|null
     */
    protected function SunNow(int $time): ?array
    {
        $minute = intdiv($time, 60);
        if ($this->sunCache !== null && $this->sunCache[0] === $minute) {
            return $this->sunCache[1];
        }
        $loc = $this->Location();
        $result = $loc === null ? null : self::SunPosition($time, $loc[0], $loc[1]);
        $this->sunCache = [$minute, $result];
        return $result;
    }

    /**
     * Sonnenstand nach dem vereinfachten Verfahren des Astronomical Almanac
     * (Genauigkeit ca. 0,1° – mehr als genug für eine Markise).
     *
     * @return array{azimuth: float, elevation: float} Azimut ab Nord im Uhrzeigersinn, Höhe über dem Horizont (Grad)
     */
    public static function SunPosition(int $time, float $latitude, float $longitude): array
    {
        $n = $time / 86400 + 2440587.5 - 2451545.0;           // Tage seit J2000.0
        $L = fmod(280.460 + 0.9856474 * $n, 360);              // mittlere Länge
        $g = deg2rad(fmod(357.528 + 0.9856003 * $n, 360));     // mittlere Anomalie
        $lambda = deg2rad($L + 1.915 * sin($g) + 0.020 * sin(2 * $g));
        $eps = deg2rad(23.439 - 0.0000004 * $n);

        $ra = atan2(cos($eps) * sin($lambda), cos($lambda));
        $dec = asin(sin($eps) * sin($lambda));

        $gmst = fmod(18.697374558 + 24.06570982441908 * $n, 24);
        $ha = deg2rad($gmst * 15 + $longitude) - $ra;          // Stundenwinkel
        $lat = deg2rad($latitude);

        $elevation = asin(sin($lat) * sin($dec) + cos($lat) * cos($dec) * cos($ha));
        $azimuth = atan2(-sin($ha), tan($dec) * cos($lat) - sin($lat) * cos($ha));

        $elevation = rad2deg($elevation);
        // Refraktion nahe dem Horizont (Sonne erscheint etwas höher)
        if ($elevation > -1.0) {
            $elevation += 1.02 / tan(deg2rad($elevation + 10.3 / ($elevation + 5.11))) / 60;
        }

        return [
            'azimuth'   => round(fmod(rad2deg($azimuth) + 360, 360), 1),
            'elevation' => round($elevation, 1),
        ];
    }

    /**
     * Prüft, ob ein Azimut im Bereich liegt; Bereiche über Nord (z. B. 300° bis 60°) sind erlaubt.
     */
    protected static function AzimuthInRange(float $azimuth, float $from, float $to): bool
    {
        if ($from <= $to) {
            return $azimuth >= $from && $azimuth <= $to;
        }
        return $azimuth >= $from || $azimuth <= $to;
    }

    /**
     * Standort aus Kern Instanzen → Location.
     *
     * @return array{0: float, 1: float}|null
     */
    protected function Location(): ?array
    {
        try {
            $ids = IPS_GetInstanceListByModuleID(self::$locationGuid);
            if (count($ids) === 0) {
                return null;
            }
            $config = json_decode(IPS_GetConfiguration($ids[0]), true);
            if (!is_array($config)) {
                return null;
            }
            if (isset($config['Location'])) {
                $loc = is_array($config['Location']) ? $config['Location'] : json_decode((string) $config['Location'], true);
                if (isset($loc['latitude'], $loc['longitude'])) {
                    return self::ValidLocation((float) $loc['latitude'], (float) $loc['longitude']);
                }
            }
            if (isset($config['Latitude'], $config['Longitude'])) {
                return self::ValidLocation((float) $config['Latitude'], (float) $config['Longitude']);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Standort', $e->getMessage(), 0);
        }
        return null;
    }

    private static function ValidLocation(float $lat, float $lon): ?array
    {
        if (abs($lat) > 90 || abs($lon) > 180 || ($lat === 0.0 && $lon === 0.0)) {
            return null;
        }
        return [$lat, $lon];
    }
}
