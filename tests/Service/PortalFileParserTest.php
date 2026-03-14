<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Tests\Service;

use Elkuku\MaxfieldBundle\Model\Portal;
use Elkuku\MaxfieldBundle\Service\PortalFileParser;
use PHPUnit\Framework\TestCase;

class PortalFileParserTest extends TestCase
{
    private PortalFileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PortalFileParser();
    }

    public function testParseExampleFile(): void
    {
        $filename = __DIR__.'/../../vendor/elkuku/maxfield-bundle/example_portals.txt';
        // Use the actual example from the Python repo if available
        $realExample = '/home/elkuku/repos/maxfield/example/example_portals.txt';
        if (!file_exists($realExample)) {
            $this->markTestSkipped('Example portal file not found.');
        }

        $portals = $this->parser->parseFile($realExample);

        $this->assertCount(18, $portals);
        $this->assertContainsOnlyInstancesOf(Portal::class, $portals);

        // Check first portal
        $this->assertSame('The Kissing Stone', $portals[0]->name);
        $this->assertEqualsWithDelta(38.032646, $portals[0]->lat, 1e-6);
        $this->assertEqualsWithDelta(-78.477578, $portals[0]->lon, 1e-6);
        $this->assertSame(0, $portals[0]->keys);
        $this->assertFalse($portals[0]->sbul);
    }

    public function testParseStringWithKeys(): void
    {
        $content = "My Portal; https://intel.ingress.com/intel?ll=1.0,-1.0&z=18&pll=1.0,-1.0; 3\n";
        $portals = $this->parser->parseString($content);
        $this->assertCount(1, $portals);
        $this->assertSame(3, $portals[0]->keys);
        $this->assertFalse($portals[0]->sbul);
    }

    public function testParseStringWithSbul(): void
    {
        $content = "My Portal; https://intel.ingress.com/intel?ll=1.0,-1.0&z=18&pll=1.0,-1.0; SBUL\n";
        $portals = $this->parser->parseString($content);
        $this->assertCount(1, $portals);
        $this->assertTrue($portals[0]->sbul);
    }

    public function testSkipsCommentLines(): void
    {
        $content = "# This is a comment\n"
            ."My Portal; https://intel.ingress.com/intel?ll=1.0,-1.0&z=18&pll=1.0,-1.0\n";
        $portals = $this->parser->parseString($content);
        $this->assertCount(1, $portals);
    }

    public function testSkipsDuplicateCoordinates(): void
    {
        $line = "My Portal; https://intel.ingress.com/intel?ll=1.0,-1.0&z=18&pll=1.0,-1.0\n";
        $portals = $this->parser->parseString($line . $line);
        $this->assertCount(1, $portals);
    }

    public function testThrowsOnMissingUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parseString("No URL Portal; just a name\n");
    }
}
