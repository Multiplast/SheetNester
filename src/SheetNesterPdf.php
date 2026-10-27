<?php

declare(strict_types=1);

namespace Multiplast\SheetNester;

use Multiplast\SheetNester\Render\PdfReport;
use Multiplast\SheetNester\Result\NestingResult;

/**
 * 1.x entry point for the PDF cutting plan.
 *
 * @deprecated Use Render\PdfReport with a NestingResult. This wrapper accepts
 *             the 1.x result array (or a NestingResult), maps the 1.x meta keys
 *             company_ico / company_dic to company_id / company_vat_id and
 *             renders with Czech labels (PdfReport::LABELS_CS) like 1.x did.
 */
class SheetNesterPdf
{
    private PdfReport $report;

    /**
     * @param array<string, mixed>|NestingResult $result Output of calculate() / solve().
     * @param array<string, string>              $meta   See PdfReport::__construct().
     */
    public function __construct(array|NestingResult $result, array $meta = [])
    {
        if (isset($meta['company_ico']) && !isset($meta['company_id'])) {
            $meta['company_id'] = $meta['company_ico'];
        }
        if (isset($meta['company_dic']) && !isset($meta['company_vat_id'])) {
            $meta['company_vat_id'] = $meta['company_dic'];
        }
        unset($meta['company_ico'], $meta['company_dic']);

        $this->report = new PdfReport(
            $result instanceof NestingResult ? $result : NestingResult::fromLegacyArray($result),
            $meta,
            PdfReport::LABELS_CS
        );
    }

    /** Returns the PDF as a binary string. */
    public function generate(): string
    {
        return $this->report->generate();
    }

    /** Saves the PDF to a file on disk. */
    public function save(string $path): void
    {
        $this->report->save($path);
    }

    /** Sends the PDF to the browser as a download. */
    public function download(string $filename = 'narezovy-plan.pdf'): void
    {
        $this->report->download($filename);
    }

    /** Sends the PDF to the browser for inline display. */
    public function inline(string $filename = 'narezovy-plan.pdf'): void
    {
        $this->report->inline($filename);
    }
}
