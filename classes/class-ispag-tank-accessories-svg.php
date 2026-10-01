<?php
/**
 * Accessoires internes des piquages (tube plongeant, tube diffuseur, tôle de déflexion) dessinés en SVG,
 * en traits interrompus (pièces cachées), pour la vue de face et la vue de dessus.
 * Même géométrie que le plan PDF (ISPAG_Tank_Drawing_Generator).
 */
class ISPAG_Tank_Accessories_SVG
{
    const GROUP_ID = 'internal-accessories';

    /** 'bend' (tube plongeant), 'spray' (tube diffuseur), 'baffle' (tôle de déflexion) ou ''. */
    public static function kind($f)
    {
        $txt = mb_strtolower((string) ($f->Accessories ?? ''));
        $id = intval($f->id_accessories ?? 0);
        if ($id === 15 || (preg_match('/spray|sparge|diffus/', $txt) && !preg_match('/bend pipe/', $txt))) {
            return 'spray';
        }
        if ($id === 16 || preg_match('/baffle|d[ée]fle/', $txt)) {
            return 'baffle';
        }
        if ($id === 14 || preg_match('/bend pipe|plongeant|plongeur/', $txt)) {
            return 'bend';
        }
        return '';
    }

    protected static function line($x1, $y1, $x2, $y2)
    {
        return sprintf("<line x1='%.1f' y1='%.1f' x2='%.1f' y2='%.1f' />", $x1, $y1, $x2, $y2);
    }

    protected static function wrap($content)
    {
        if ($content === '') {
            return '';
        }
        return "<g id='" . self::GROUP_ID . "' style='fill:none;stroke:#444;stroke-width:2;stroke-dasharray:14,8'>" . $content . '</g>';
    }

    /** Tube vu de côté le long de la ligne brisée $pts (axe), rayon $r, avec pli aux angles et bouchon final. */
    protected static function tube(array $pts, $r)
    {
        $svg = '';
        $n = count($pts);
        for ($i = 0; $i < $n - 1; $i++) {
            $dx = $pts[$i + 1][0] - $pts[$i][0];
            $dy = $pts[$i + 1][1] - $pts[$i][1];
            $l = sqrt($dx * $dx + $dy * $dy) ?: 1;
            $nx = -$dy / $l * $r;
            $ny = $dx / $l * $r;
            foreach ([1, -1] as $sg) {
                $svg .= self::line($pts[$i][0] + $sg * $nx, $pts[$i][1] + $sg * $ny, $pts[$i + 1][0] + $sg * $nx, $pts[$i + 1][1] + $sg * $ny);
            }
            if ($i > 0) {
                $svg .= self::line($pts[$i][0] + $nx, $pts[$i][1] + $ny, $pts[$i][0] - $nx, $pts[$i][1] - $ny);
            }
        }
        $last = $pts[$n - 1];
        $dx = $last[0] - $pts[$n - 2][0];
        $dy = $last[1] - $pts[$n - 2][1];
        $l = sqrt($dx * $dx + $dy * $dy) ?: 1;
        $nx = -$dy / $l * $r;
        $ny = $dx / $l * $r;
        return $svg . self::line($last[0] + $nx, $last[1] + $ny, $last[0] - $nx, $last[1] - $ny);
    }

    /** Rectangle orienté selon (ux,uy) entre les rayons $d1 et $d2, de largeur $w (contour seul). */
    protected static function radial_rect($cx, $cy, $ux, $uy, $d1, $d2, $w)
    {
        $px = -$uy * $w / 2;
        $py = $ux * $w / 2;
        $pts = [
            [$cx + $ux * $d1 + $px, $cy + $uy * $d1 + $py],
            [$cx + $ux * $d2 + $px, $cy + $uy * $d2 + $py],
            [$cx + $ux * $d2 - $px, $cy + $uy * $d2 - $py],
            [$cx + $ux * $d1 - $px, $cy + $uy * $d1 - $py],
        ];
        $p = '';
        foreach ($pts as $i => $pt) {
            $p .= ($i ? 'L' : 'M') . sprintf('%.1f %.1f ', $pt[0], $pt[1]);
        }
        return "<path d='{$p}Z' />";
    }

    /** Vue de face : x depuis le bord gauche de la cuve, y depuis son sommet (repère du groupe principal du SVG). */
    public static function front($fittings, $diam, $height)
    {
        $svg = '';
        foreach ((array) $fittings as $f) {
            $kind = self::kind($f);
            $h = floatval($f->Height ?? 0);
            if ($kind === '' || $h > $height) {
                continue;
            }
            $sin = sin(deg2rad(intval($f->Angle ?? 0)));
            $dn = max(20, floatval($f->InternalDiamter ?? 50));
            $r = $dn / 2;
            $x0 = $diam / 2 * (1 + $sin);
            $y0 = $height - $h;
            $p = abs($sin);
            $dir = $sin > 0 ? -1 : 1;

            if ($p < 0.25) { // vu par son extrémité
                $svg .= "<circle cx='" . round($x0, 1) . "' cy='" . round($y0, 1) . "' r='{$r}' />";
                continue;
            }
            if ($kind === 'bend') {
                $up = $h > $height / 2 ? -1 : 1;
                $x1 = $x0 + $dir * 120 * $p;
                $x2 = $x1 + $dir * 200 * M_SQRT1_2 * $p;
                $y2 = $y0 + $up * 200 * M_SQRT1_2;
                $svg .= self::tube([[$x0, $y0], [$x1, $y0], [$x2, $y2]], $r);
            } elseif ($kind === 'spray') {
                $x1 = $x0 + $dir * 0.7 * $diam * $p;
                $svg .= self::tube([[$x0, $y0], [$x1, $y0]], $r);
                for ($i = 1; $i <= 8; $i++) { // perçages horizontaux, vus de face
                    $svg .= "<circle cx='" . round($x0 + ($x1 - $x0) * ($i / 9), 1) . "' cy='" . round($y0, 1) . "' r='6' style='stroke-dasharray:none' />";
                }
            } else {
                $xp = $x0 + $dir * 70 * $p;
                $half = 1.1 * $dn;
                $svg .= "<rect x='" . round(min($xp, $xp + $dir * 10), 1) . "' y='" . round($y0 - $half, 1) . "' width='10' height='" . round(2 * $half, 1) . "' />";
            }
        }
        return self::wrap($svg);
    }

    /** Vue de dessus : centre ($cx,$cy) du cercle de la cuve, 0° = bas, 90° = droite. */
    public static function top($fittings, $cx, $cy, $diam, $tank_height)
    {
        $svg = '';
        $r = $diam / 2;
        foreach ((array) $fittings as $f) {
            $kind = self::kind($f);
            if ($kind === '' || floatval($f->Height ?? 0) > $tank_height) {
                continue;
            }
            $a = deg2rad(intval($f->Angle ?? 0));
            $ux = sin($a);
            $uy = cos($a);
            $dn = max(20, floatval($f->InternalDiamter ?? 50));
            if ($kind === 'bend') {
                $svg .= self::radial_rect($cx, $cy, $ux, $uy, $r - (120 + 200 * M_SQRT1_2), $r, $dn);
            } elseif ($kind === 'spray') {
                $svg .= self::radial_rect($cx, $cy, $ux, $uy, $r - 0.7 * $diam, $r, $dn);
                for ($i = 1; $i <= 8; $i++) { // jets horizontaux de chaque côté du tube
                    $d = $r - 0.7 * $diam * ($i / 9);
                    foreach ([1, -1] as $sg) {
                        $svg .= self::line($cx + $ux * $d - $sg * $uy * $dn / 2, $cy + $uy * $d + $sg * $ux * $dn / 2, $cx + $ux * $d - $sg * $uy * ($dn / 2 + 30), $cy + $uy * $d + $sg * $ux * ($dn / 2 + 30));
                    }
                }
            } else {
                $svg .= self::radial_rect($cx, $cy, $ux, $uy, $r - 80, $r - 70, 2.2 * $dn);
            }
        }
        return self::wrap($svg);
    }

    /** Hauteur du fond bombé selon matière/diamètre (assets/json/tank_data.json). */
    public static function bottom_height($tank_data)
    {
        $file = __DIR__ . '/../assets/json/tank_data.json';
        $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        $material = is_array($tank_data) ? ($tank_data['conception']->Material ?? null) : null;
        $diam = is_array($tank_data) ? ($tank_data['dimensions']->Diameter ?? null) : null;
        return intval($data['arrayBottomHeight'][$material][$diam] ?? 0);
    }

    /**
     * Géométrie schématique des serpentins (échangeurs) d'après leur surface.
     * Le tube (DN25, ou DN32 pour un Spiraflex) est enroulé en spires ; plusieurs échangeurs se partagent la largeur de la cuve ;
     * si une seule couche serait trop haute, le tube est réparti sur 2 ou 3 couches concentriques.
     * Retourne une liste de ['cx' (mm depuis le bord gauche), 'z0' (hauteur du bas depuis le sol), 'h', 'd', 'layers' => [['dc', 'turns']]].
     */
    public static function coil_geometry($coils, $diam, $height, $gc, $bh)
    {
        $coils = array_values((array) $coils);
        $n = count($coils);
        if (!$n) {
            return [];
        }
        $body = $height - $gc - 2 * $bh;
        $max_h = max(300, $body * 0.7);
        $slot = ($diam - 200) / $n;
        $out = [];
        foreach ($coils as $i => $c) {
            $surface = floatval($c['coilSurface'] ?? 0);
            if ($surface <= 0) {
                continue;
            }
            $d = (($c['spiraflex'] ?? '') == '1') ? 42.4 : 33.7;
            $len = $surface * 1e6 / (M_PI * $d);
            $pitch = $d * 1.6;
            $dc0 = max(150, $slot - 2 * $d);
            $layers = [];
            $h = 0;
            for ($k = 1; $k <= 3; $k++) {
                $layers = [];
                $h = 0;
                for ($j = 0; $j < $k; $j++) {
                    $dc = max(100, $dc0 - 2 * $j * $pitch);
                    $turns = ($len / $k) / (M_PI * $dc);
                    $layers[] = ['dc' => $dc, 'turns' => $turns];
                    $h = max($h, $turns * $pitch);
                }
                if ($h <= $max_h) {
                    break;
                }
            }
            $h = min($h, $max_h);
            $out[] = [
                'cx' => 100 + $slot * ($i + 0.5),
                'z0' => $gc + $bh + 120,
                'h' => $h,
                'd' => $d,
                'layers' => $layers,
            ];
        }
        return $out;
    }

    /** Serpentins en vue de face (spires inclinées, traits fins continus). */
    public static function coils_front($geo, $height)
    {
        $svg = '';
        foreach ($geo as $g) {
            foreach ($g['layers'] as $l) {
                $r = $l['dc'] / 2;
                $count = max(2, min(80, (int) round($l['turns'])));
                $pitch = $g['h'] / $count;
                for ($t = 0; $t < $count; $t++) {
                    $y = $height - ($g['z0'] + $t * $pitch);
                    $svg .= self::line($g['cx'] - $r, $y, $g['cx'] + $r, $y - $pitch / 2);
                }
                $svg .= self::line($g['cx'] - $r, $height - $g['z0'], $g['cx'] - $r, $height - $g['z0'] - $g['h']);
                $svg .= self::line($g['cx'] + $r, $height - $g['z0'], $g['cx'] + $r, $height - $g['z0'] - $g['h']);
            }
        }
        return $svg === '' ? '' : "<g id='internal-coils' style='fill:none;stroke:#666;stroke-width:1.5'>" . $svg . '</g>';
    }

    /** Serpentins en vue de dessus : un cercle par couche. */
    public static function coils_top($geo, $cx, $cy, $diam)
    {
        $svg = '';
        foreach ($geo as $g) {
            foreach ($g['layers'] as $l) {
                $svg .= "<circle cx='" . round($cx + $g['cx'] - $diam / 2, 1) . "' cy='" . round($cy, 1) . "' r='" . round($l['dc'] / 2, 1) . "' />";
            }
        }
        return $svg === '' ? '' : "<g id='internal-coils' style='fill:none;stroke:#666;stroke-width:1.5'>" . $svg . '</g>';
    }
}
