<?php

namespace App\Services\PDF;

use App\Models\User;

class CustomTCPDF extends \TCPDF
{
    /**
     * RGBA available colors array.
     *
     * @var \int[][]
     */
    private static $color = [
        'black' => [
            'r' => 43,
            'g' => 46,
            'b' => 52,
            'a' => 1,
        ],
        'gray' => [
            'r' => 133,
            'g' => 134,
            'b' => 138,
            'a' => 1,
        ],
        'white' => [
            'r' => 255,
            'g' => 255,
            'b' => 255,
            'a' => 1,
        ],
    ];

    /**
     * @var string
     */
    private $footer_text;

    /**
     * @var string
     */
    private $text_color = 'gray';

    /**
     * @var string
     */
    private $text_style = 'gray';

    /**
     * Set the footer text.
     *
     * @param User  $user
     * @param array $footer
     */
    public function setFooterFromUser(User $user, array $footer = []): void
    {
        $this->text_color = 'gray';
        $this->text_style = '';
        $this->footer_text = view('cms.report.partials.pdf.footer', compact('user', 'footer'))->render();
    }

    /**
     * Set the powered footer text.
     *
     * @param string $poweredByText
     */
    public function setPoweredByFooter(string $poweredByText): void
    {
        $this->text_color = 'white';
        $this->text_style = '';
        $this->footer_text = view('cms.report.partials.pdf.footer', compact('poweredByText'))->render();
    }

    /**
     * {@inheritdoc}
     */
    public function Footer(): void
    {
        // Position at 15 mm from bottom
        $this->SetY(-15);
        // Set font
        $this->SetFont('helvetica', $this->text_style, 8);
        $this->SetTextColor(
            self::$color[$this->text_color]['r'],
            self::$color[$this->text_color]['g'],
            self::$color[$this->text_color]['b']
        );

        // Page number
        $this->writeHTML($this->footer_text, false, false, false, false, 'C');
    }

    /**
     * @return array
     *
     * @param mixed $color
     */
    public function rgbaColor($color): array
    {
        return self::$color[$color];
    }

    public function LinearGradientTransparent($x, $y, $w, $h, $col1 = [], $col2 = [], $coords = [0, 0, 1, 0]): void
    {
        $this->Clip($x, $y, $w, $h);
        $this->Gradient(2, $coords, [['color' => $col1, 'offset' => 0, 'exponent' => 1, 'opacity' => 1], ['color' => $col2, 'offset' => 1, 'exponent' => 1, 'opacity' => 0]], [], false);
    }
}
