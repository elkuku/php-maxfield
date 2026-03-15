<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Service;

use Elkuku\MaxfieldBundle\Model\Plan;
use Intervention\Gif\Builder as GifBuilder;

/**
 * Handles generating map images and animating GIF for the plan.
 */
class ImageGenerator
{
    private const IMAGE_SIZE = 640;
    private const FONT_PATH = '/usr/share/fonts/noto/NotoSans-Bold.ttf';
    private const AP_PER_PORTAL = 1750;
    private const AP_PER_LINK = 313;
    private const AP_PER_FIELD = 1250;

    public function generateAll(Plan $plan, string $outdir, bool $resColors = false, bool $verbose = false): void
    {
        if (!is_dir($outdir)) {
            mkdir($outdir, 0777, true);
        }

        $colorRgba = $resColors ? [0, 0, 255] : [0, 128, 0]; // Blue or Darker Green

        $baseImage = $this->fetchBaseMap($plan);

        if ($verbose) {
            echo "Generating portal map.\n";
        }
        $portalMap = $this->drawPortals($plan, $baseImage, $colorRgba);
        $portalMapWithTitle = $this->cloneImage($portalMap);
        $this->drawTitle($portalMapWithTitle, sprintf("Portal Map: %d", \count($plan->portals)));
        imagepng($portalMapWithTitle, $outdir . '/portal_map.png');
        imagedestroy($portalMapWithTitle);

        if ($verbose) {
            echo "Generating link map.\n";
        }
        $linkMap = $this->drawLinksAndFields($plan, $portalMap, $colorRgba);
        $linkMapWithTitle = $this->cloneImage($linkMap);
        $this->drawTitle($linkMapWithTitle, sprintf("Link Map: %d links and %d fields", $plan->graph->numLinks, $plan->graph->numFields));
        imagepng($linkMapWithTitle, $outdir . '/link_map.png');
        imagedestroy($linkMapWithTitle);

        if ($verbose) {
            echo "Generating step-by-step plots.\n";
        }
        $this->generateStepPlots($plan, $baseImage, $colorRgba, $outdir, $verbose);

        imagedestroy($baseImage);
        imagedestroy($portalMap);
        imagedestroy($linkMap);
    }

    private function fetchBaseMap(Plan $plan): \GdImage
    {
        $centerLat = deg2rad($plan->llCenter[1]);
        $centerLon = deg2rad($plan->llCenter[0]);
        $zoom = $plan->zoom;

        // Calculate center tile coordinates (fractional)
        $n = 2 ** $zoom;
        $centerTileX = ($plan->llCenter[0] + 180.0) / 360.0 * $n;
        $centerTileY = (1.0 - log(tan($centerLat) + (1.0 / cos($centerLat))) / M_PI) / 2.0 * $n;

        // We want 640x640. Tiles are 256x256.
        // A 4x4 grid (1024x1024) centered on our center tile guarantees coverage.
        $startTileX = (int) floor($centerTileX - 1.25);
        $startTileY = (int) floor($centerTileY - 1.25);

        $canvas = imagecreatetruecolor(4 * 256, 4 * 256);
        $bg = imagecolorallocate($canvas, 240, 240, 240);
        imagefill($canvas, 0, 0, $bg);

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'user_agent' => 'MaxfieldGenerator/1.0 PHP (+https://github.com/elkuku/maxfield-php-2)',
            ]
        ]);

        for ($dx = 0; $dx < 4; $dx++) {
            for ($dy = 0; $dy < 4; $dy++) {
                $tx = $startTileX + $dx;
                $ty = $startTileY + $dy;
                if ($tx < 0 || $ty < 0 || $tx >= $n || $ty >= $n) continue;

                $url = sprintf('https://tile.openstreetmap.org/%d/%d/%d.png', $zoom, $tx, $ty);
                $tileContent = @file_get_contents($url, false, $context);
                if ($tileContent !== false) {
                    $tileImg = @imagecreatefromstring($tileContent);
                    if ($tileImg !== false) {
                        imagecopy($canvas, $tileImg, (int)($dx * 256), (int)($dy * 256), 0, 0, 256, 256);
                        imagedestroy($tileImg);
                    }
                }
            }
        }

        // Now crop 640x640 centered on the fractional $centerTileX, $centerTileY
        // In our 4x4 canvas, $startTileX corresponds to pixel 0.
        $centerXInCanvas = ($centerTileX - $startTileX) * 256;
        $centerYInCanvas = ($centerTileY - $startTileY) * 256;

        $cropX = (int) ($centerXInCanvas - self::IMAGE_SIZE / 2);
        $cropY = (int) ($centerYInCanvas - self::IMAGE_SIZE / 2);

        $result = imagecreatetruecolor(self::IMAGE_SIZE, self::IMAGE_SIZE);
        $bgRes = imagecolorallocate($result, 240, 240, 240);
        imagefill($result, 0, 0, $bgRes);

        imagecopy($result, $canvas, 0, 0, (int)$cropX, (int)$cropY, self::IMAGE_SIZE, self::IMAGE_SIZE);
        imagedestroy($canvas);

        return $result;
    }

    private function getAlignments(Plan $plan): array
    {
        $n = \count($plan->portals);
        $ha = array_fill(0, $n, 'center');
        $agentHa = array_fill(0, $n, 'center');
        $va = array_fill(0, $n, 'center');
        $agentVa = array_fill(0, $n, 'center');

        for ($i = 0; $i < $n; $i++) {
            $dists = $plan->portalsDists[$i];
            
            $minDist = PHP_INT_MAX;
            $nearest = -1;
            for ($j = 0; $j < $n; $j++) {
                if ($i !== $j && $dists[$j] < $minDist) {
                    $minDist = $dists[$j];
                    $nearest = $j;
                }
            }

            if ($nearest >= 0) {
                if ($plan->portalsMer[$i][0] < $plan->portalsMer[$nearest][0]) {
                    $ha[$i] = 'right';
                    $agentHa[$i] = 'left';
                } elseif ($plan->portalsMer[$i][0] > $plan->portalsMer[$nearest][0]) {
                    $ha[$i] = 'left';
                    $agentHa[$i] = 'right';
                }

                if ($plan->portalsMer[$i][1] < $plan->portalsMer[$nearest][1]) {
                    $va[$i] = 'top';
                    $agentVa[$i] = 'bottom';
                } elseif ($plan->portalsMer[$i][1] > $plan->portalsMer[$nearest][1]) {
                    $va[$i] = 'bottom';
                    $agentVa[$i] = 'top';
                }
            }
        }
        return [$ha, $va, $agentHa, $agentVa];
    }

    private function drawText(\GdImage $img, int $x, int $y, string $text, string $ha, string $va, bool $bg = false, int $fontSize = 10): void
    {
        $bbox = imagettfbbox((float)$fontSize, 0, self::FONT_PATH, $text);
        if ($bbox === false) {
             // Fallback to imagestring if TTF fails for some reason
             imagestring($img, 5, $x, $y, $text, imagecolorallocate($img, 0,0,0));
             return;
        }

        $w = abs($bbox[4] - $bbox[0]);
        $h = abs($bbox[5] - $bbox[1]);

        $dx = 0;
        if ($ha === 'right') {
            $dx = -$w - 5;
        } elseif ($ha === 'left') {
            $dx = 5;
        } else {
            $dx = -$w / 2;
        }

        $dy = 0;
        if ($va === 'bottom') {
            $dy = -$h / 2 + 5;
        } elseif ($va === 'top') {
            $dy = $h / 2 - 5;
        } else {
            $dy = $h / 2;
        }

        $tx = (int) ($x + $dx);
        $ty = (int) ($y + $dy);

        if ($bg) {
            $bgColor = imagecolorallocatealpha($img, 255, 0, 255, 63); // magenta semi-transparent
            imagefilledrectangle($img, (int) ($tx - 2), (int) ($ty - $h - 2), (int) ($tx + $w + 2), (int) ($ty + 2), $bgColor);
        }

        $black = imagecolorallocate($img, 0, 0, 0);
        imagettftext($img, (float)$fontSize, 0, $tx, $ty, $black, self::FONT_PATH, $text);
    }

    private function cloneImage(\GdImage $src): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        imagesavealpha($dst, true);
        
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        return $dst;
    }

    private function drawPortals(Plan $plan, \GdImage $base, array $colorRgba): \GdImage
    {
        $img = $this->cloneImage($base);
        [$ha, $va] = $this->getAlignments($plan);

        $color = imagecolorallocate($img, $colorRgba[0], $colorRgba[1], $colorRgba[2]);
        $black = imagecolorallocate($img, 0, 0, 0);

        foreach ($plan->portalsMer as $i => [$x, $y]) {
            imagefilledellipse($img, (int) $x, (int) $y, 10, 10, $color);
            imagesetthickness($img, 1);
            imagearc($img, (int) $x, (int) $y, 10, 10, 0, 360, $black);
            
            $this->drawText($img, (int)$x, (int)$y, (string)$i, $ha[$i], $va[$i], false, 10);
        }
        
        return $img;
    }

    private function drawTitle(\GdImage $img, string $title): void
    {
        $bg = imagecolorallocate($img, 255, 255, 255); 
        $black = imagecolorallocate($img, 0, 0, 0);
        
        imagefilledrectangle($img, 0, 0, self::IMAGE_SIZE, 25, $bg);
        imagettftext($img, 14.0, 0, 10, 20, $black, self::FONT_PATH, $title);
    }

    private function drawLinksAndFields(Plan $plan, \GdImage $portalMap, array $colorRgba): \GdImage
    {
        $img = $this->cloneImage($portalMap);
        
        $colorLine = imagecolorallocate($img, $colorRgba[0], $colorRgba[1], $colorRgba[2]);
        $colorField = imagecolorallocatealpha($img, $colorRgba[0], $colorRgba[1], $colorRgba[2], 85);

        // draw lines and fields
        $orderedLinks = $plan->graph->getOrderedLinks();
        foreach ($orderedLinks as $linkObj) {
            $i = $linkObj->origin;
            $j = $linkObj->destination;
            
            imagesetthickness($img, 2);
            imageline(
                $img,
                (int) $plan->portalsMer[$i][0], (int) $plan->portalsMer[$i][1],
                (int) $plan->portalsMer[$j][0], (int) $plan->portalsMer[$j][1],
                $colorLine
            );
            
            foreach ($linkObj->fields as $fld) {
                $points = [];
                foreach ($fld as $node) {
                    $points[] = (int) $plan->portalsMer[$node][0];
                    $points[] = (int) $plan->portalsMer[$node][1];
                }
                imagefilledpolygon($img, $points, $colorField);
            }
        }
        
        return $img;
    }

    private function generateStepPlots(Plan $plan, \GdImage $base, array $colorRgba, string $outdir, bool $verbose): void
    {
        $framesDir = $outdir . '/frames';
        if (!is_dir($framesDir)) {
            mkdir($framesDir, 0777, true);
        }

        [$ha, $va, $agentHa, $agentVa] = $this->getAlignments($plan);

        $colorLine = imagecolorallocate($base, $colorRgba[0], $colorRgba[1], $colorRgba[2]);
        $colorField = imagecolorallocatealpha($base, $colorRgba[0], $colorRgba[1], $colorRgba[2], 85);
        $colorNewField = imagecolorallocatealpha($base, 255, 0, 0, 85); // red transparent
        $colorMove = imagecolorallocate($base, 255, 0, 255); // magenta

        $numLinks = 0;
        $numFields = 0;
        $numAp = \count($plan->portals) * self::AP_PER_PORTAL;

        $agentsLastPos = [];
        $firstAssForAgent = [];
        foreach ($plan->assignments as $ass) {
            if (!isset($firstAssForAgent[$ass->agent])) {
                $firstAssForAgent[$ass->agent] = $ass;
            }
        }

        $basePortals = $this->cloneImage($base);
        $colorPortal = imagecolorallocate($basePortals, $colorRgba[0], $colorRgba[1], $colorRgba[2]);
        $black = imagecolorallocate($basePortals, 0, 0, 0);

        foreach ($plan->portalsMer as $i => [$x, $y]) {
            imagefilledellipse($basePortals, (int) $x, (int) $y, 10, 10, $colorPortal);
            imagesetthickness($basePortals, 1);
            imagearc($basePortals, (int) $x, (int) $y, 10, 10, 0, 360, $black);
            $this->drawText($basePortals, (int)$x, (int)$y, (string)$i, $ha[$i], $va[$i], false, 10);
        }

        $img0 = $this->cloneImage($basePortals);
        for ($agent = 0; $agent < $plan->numAgents; $agent++) {
            $portalIdx = $firstAssForAgent[$agent]->location;
            $agentsLastPos[$agent] = $portalIdx;

            $x = $plan->portalsMer[$portalIdx][0];
            $y = $plan->portalsMer[$portalIdx][1];
            
            $this->drawText($img0, (int)$x, (int)$y, 'A' . ($agent + 1), $agentHa[$portalIdx], $agentVa[$portalIdx], true, 10);
        }
        $title0 = sprintf("Time: 00:00:00  Links:    0  Fields:    0  AP: %7d", $numAp);
        $this->drawTitle($img0, $title0);

        $frame = 0;
        $framesFiles = [];
        
        $fname = $framesDir . sprintf('/frame_%05d.gif', $frame++);
        imagegif($img0, $fname);
        $framesFiles[] = $fname;
        imagedestroy($img0);

        $arrivals = array_unique(array_map(fn($a) => $a->arrive, $plan->assignments));
        sort($arrivals);

        $currentRender = $this->cloneImage($basePortals);
        imagealphablending($currentRender, true);

        foreach ($arrivals as $arrival) {
            $myAss = array_filter($plan->assignments, fn($a) => $a->arrive === $arrival);
            $myAss = array_values($myAss); // re-index

            $movedMap = $this->cloneImage($currentRender);
            $moved = false;

            foreach ($myAss as $ass) {
                $lastOrigin = $agentsLastPos[$ass->agent];
                $thisOrigin = $ass->location;
                if ($lastOrigin !== $thisOrigin) {
                    imagesetthickness($movedMap, 1);
                    imagedashedline(
                        $movedMap,
                        (int) $plan->portalsMer[$lastOrigin][0], (int) $plan->portalsMer[$lastOrigin][1],
                        (int) $plan->portalsMer[$thisOrigin][0], (int) $plan->portalsMer[$thisOrigin][1],
                        $colorMove
                    );
                    $agentsLastPos[$ass->agent] = $thisOrigin;
                    $moved = true;
                }
            }

            if ($moved) {
                $this->drawAgents($movedMap, $plan, $agentsLastPos, $agentHa, $agentVa);
                $this->saveFrame($movedMap, $arrival, $numLinks, $numFields, $numAp, $framesDir, $frame, $framesFiles);
            }
            imagedestroy($movedMap);

            $linkMap = $this->cloneImage($currentRender);
            foreach ($myAss as $ass) {
                $link = [$ass->location, $ass->link];
                $edge = $plan->graph->getLink($link[0], $link[1]);

                imagesetthickness($currentRender, 2);
                imageline(
                    $currentRender,
                    (int) $plan->portalsMer[$link[0]][0], (int) $plan->portalsMer[$link[0]][1],
                    (int) $plan->portalsMer[$link[1]][0], (int) $plan->portalsMer[$link[1]][1],
                    $colorLine
                );
                imagesetthickness($linkMap, 2);
                imageline(
                    $linkMap,
                    (int) $plan->portalsMer[$link[0]][0], (int) $plan->portalsMer[$link[0]][1],
                    (int) $plan->portalsMer[$link[1]][0], (int) $plan->portalsMer[$link[1]][1],
                    $colorLine
                );

                $numLinks++;
                $numAp += self::AP_PER_LINK;

                foreach ($edge->fields as $fld) {
                    $points = [];
                    foreach ($fld as $node) {
                        $points[] = (int) $plan->portalsMer[$node][0];
                        $points[] = (int) $plan->portalsMer[$node][1];
                    }
                    imagefilledpolygon($linkMap, $points, $colorNewField);
                    imagefilledpolygon($currentRender, $points, $colorField);
                    $numFields++;
                    $numAp += self::AP_PER_FIELD;
                }
            }

            $this->drawAgents($linkMap, $plan, $agentsLastPos, $agentHa, $agentVa);
            $this->saveFrame($linkMap, $arrival, $numLinks, $numFields, $numAp, $framesDir, $frame, $framesFiles);
            imagedestroy($linkMap);
        }
        
        imagedestroy($currentRender);
        imagedestroy($basePortals);
        
        // Output GIF
        $gifFname = $outdir . '/plan_movie.gif';
        
        $gif = GifBuilder::canvas(self::IMAGE_SIZE, self::IMAGE_SIZE);
        foreach ($framesFiles as $f) {
            // delay is float seconds, 0.5s = 50ms in gif time
            $gif->addFrame($f, 0.5);
        }
        $gif->setLoops(0);
        
        file_put_contents($gifFname, $gif->encode());
        
        if ($verbose) {
            echo "Frames saved to $framesDir\n";
            echo "GIF saved to $gifFname\n\n";
        }
    }

    private function drawAgents(\GdImage $img, Plan $plan, array $agentsPos, array $agentHa, array $agentVa): void
    {
        foreach ($agentsPos as $agent => $portalIdx) {
            $x = (int) $plan->portalsMer[$portalIdx][0];
            $y = (int) $plan->portalsMer[$portalIdx][1];
            $this->drawText($img, $x, $y, 'A' . ($agent + 1), $agentHa[$portalIdx], $agentVa[$portalIdx], true, 10);
        }
    }

    private function saveFrame(\GdImage $img, int $arrival, int $links, int $fields, int $ap, string $outdir, int &$frame, array &$files): void
    {
        $hr = (int) ($arrival / 3600);
        $mn = (int) (($arrival - $hr * 3600) / 60);
        $sc = $arrival % 60;

        $title = sprintf("Time: %02d:%02d:%02d  Links: %4d  Fields: %4d  AP: %7d", $hr, $mn, $sc, $links, $fields, $ap);
        $this->drawTitle($img, $title);
        
        $fname = $outdir . sprintf('/frame_%05d.gif', $frame++);
        imagegif($img, $fname);
        $files[] = $fname;
    }
}
