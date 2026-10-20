<?php

namespace ErnestDefoe\VelvetRope\Tests\integration\api;

use ErnestDefoe\VelvetRope\Formatter\Transformer;
use ErnestDefoe\VelvetRope\Verdict;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Media other formatters write: FoF Upload's tags and Scribe's HTML elements.
 */
class TransformerTest extends TestCase
{
    private function lock(string $xml, array $kinds): string
    {
        return (new Transformer())->apply($xml, new Verdict(Verdict::LOCK, $kinds, 0, 'guest'));
    }

    #[Test]
    public function fof_upload_images_and_files_are_locked_by_kind()
    {
        $xml = '<r><p>See <UPL-IMAGE-PREVIEW url="https://f.example/a.png">[upl-image-preview url=https://f.example/a.png]</UPL-IMAGE-PREVIEW>'
            .' and <UPL-FILE uuid="x" base_name="plans.pdf">[upl-file uuid=x]plans.pdf[/upl-file]</UPL-FILE></p></r>';

        $images = $this->lock($xml, ['image']);
        $this->assertStringNotContainsString('f.example', $images);
        $this->assertStringContainsString('plans.pdf', $images, 'files left alone');

        $files = $this->lock($xml, ['file']);
        $this->assertStringContainsString('f.example', $files, 'images left alone');
        $this->assertStringNotContainsString('plans.pdf', $files);
    }

    #[Test]
    public function scribe_html_elements_are_locked_and_its_links_are_not()
    {
        $xml = '<r xmlns:html="urn:s9e:TextFormatter:html"><html:p>Hi <html:img src="https://i.example/x.jpg"/>'
            .'<html:a href="https://l.example">read</html:a><html:iframe src="https://v.example/embed"/></html:p></r>';

        $out = $this->lock($xml, ['image', 'embed']);
        $this->assertStringNotContainsString('i.example', $out);
        $this->assertStringNotContainsString('v.example', $out);
        $this->assertStringContainsString('https://l.example', $out);
        $this->assertSame(2, substr_count($out, 'VELVETROPE-LOCK'));
    }

    #[Test]
    public function a_preview_cut_inside_markup_stays_well_formed()
    {
        $xml = '<r><p>One <STRONG><s>**</s>two three four<e>**</e></STRONG> five</p><p>six</p></r>';
        $out = (new Transformer())->apply($xml, new Verdict(Verdict::LEAD, [], 10, 'guest'));

        $this->assertNotFalse(@simplexml_load_string($out));
        $this->assertStringNotContainsString('five', $out);
        $this->assertStringNotContainsString('six', $out);
        $this->assertStringContainsString('VELVETROPE-MORE', $out);
    }
}
