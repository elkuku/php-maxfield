<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Tests\Service;

use Elkuku\MaxfieldBundle\Service\GeometryService;
use PHPUnit\Framework\TestCase;

class GeometryServiceTest extends TestCase
{
    private GeometryService $geo;

    protected function setUp(): void
    {
        $this->geo = new GeometryService();
    }

    public function testSphericalDistanceSamePoint(): void
    {
        $ll = [[deg2rad(0.0), deg2rad(0.0)]];
        $dists = $this->geo->calcSphericalDistances($ll);
        $this->assertSame(0, $dists[0][0]);
    }

    public function testSphericalDistanceKnownPair(): void
    {
        // Equator, 1 degree apart ≈ 111.195 km
        $ll = [
            [deg2rad(0.0), deg2rad(0.0)],
            [deg2rad(1.0), deg2rad(0.0)],
        ];
        $dists = $this->geo->calcSphericalDistances($ll);
        $this->assertEqualsWithDelta(111_195, $dists[0][1], 500);
    }

    public function testSphericalDistanceSymmetric(): void
    {
        $ll = [
            [deg2rad(-78.477578), deg2rad(38.032646)],
            [deg2rad(-78.47745),  deg2rad(38.032572)],
        ];
        $dists = $this->geo->calcSphericalDistances($ll);
        $this->assertSame($dists[0][1], $dists[1][0]);
    }

    public function testGnomonicProjectionDimensions(): void
    {
        $ll = [
            [deg2rad(-78.477578), deg2rad(38.032646)],
            [deg2rad(-78.47745),  deg2rad(38.032572)],
            [deg2rad(-78.477417), deg2rad(38.032190)],
        ];
        $gno = $this->geo->gnomonicProjection($ll);
        $this->assertCount(3, $gno);
        foreach ($gno as $p) {
            $this->assertCount(2, $p);
        }
    }

    public function testConvexHullSquare(): void
    {
        // Unit square — all 4 vertices should be on hull
        $points = [
            [0.0, 0.0],
            [1.0, 0.0],
            [1.0, 1.0],
            [0.0, 1.0],
        ];
        $hull = $this->geo->convexHull($points);
        $this->assertCount(4, $hull);
        sort($hull);
        $this->assertSame([0, 1, 2, 3], $hull);
    }

    public function testConvexHullWithInteriorPoint(): void
    {
        // Center point should NOT be on hull
        $points = [
            [0.0, 0.0],
            [1.0, 0.0],
            [1.0, 1.0],
            [0.0, 1.0],
            [0.5, 0.5], // interior
        ];
        $hull = $this->geo->convexHull($points);
        $this->assertNotContains(4, $hull);
        $this->assertCount(4, $hull);
    }
}
