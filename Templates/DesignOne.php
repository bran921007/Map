<?php

namespace App\Services\PDF\Templates;

use Arr;
use Cache;
use DOMDocument;
use App\Models\User;
use App\Models\Image;
use App\Models\Company;
use App\Models\Project;
use App\Models\Orgchart;
use App\Models\ReportDraft;
use App\Services\PDF\PdfService;
use Illuminate\Http\UploadedFile;
use App\Contracts\PDF\PdfContract;
use Illuminate\Support\Collection;
use App\Events\CompanyReportChanged;
use mastani\GoogleStaticMap\MapType;
use App\Repositories\CompanyRepository;
use App\Services\PDF\CustomTCPDF as TCPDF;
use App\Contracts\File\FileManagerContract;
use mastani\GoogleStaticMap\GoogleStaticMap;

class DesignOne extends PdfService implements PdfContract
{
    /**
     * PdfService constructor.
     */
    public function __construct()
    {
        self::$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        // remove default header/footer
        self::$pdf->setPrintHeader(false);
        self::$pdf->setPrintFooter(false);
        // set default monospaced font
        self::$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        // set margins
        self::$pdf->SetMargins(10, 10, 10);
        // set auto page breaks
        self::$pdf->SetAutoPageBreak(true, 10);
        // set image scale factor
        self::$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        self::$pdf->SetFontSize(12);

        self::$pdf->SetDisplayMode('fullpage', 'SinglePage', 'UseNone');
    }

    /**
     * {@inheritdoc}
     */
    public static function make(
        User $user,
        ReportDraft $reportDraft,
        int $total
    ): PdfContract
    {
        $reportDraft = app()->make(CompanyRepository::class)->getDraft($user->company, $reportDraft->id);
        $reportDraft->cover_image = $reportDraft->cover_image ?: $user->company->logo;
        $projects = $user->company->projects()
            ->whereIn('projects.id', $reportDraft->selected_projects)
            ->setEagerLoads(['sectors' => static function () {
            }, 'certificates' => static function () {
            }])
            ->get()->unique('id')
            ->sortBy('id');

        $progress = 0;
        broadcast(new CompanyReportChanged($user, $reportDraft->id, 'Generating cover page', $progress, $total));
        $progress++;

        $pdf = new self();
        $pdf->user = $user;
        $pdf->reportDraft = $reportDraft;
        $pdf->progress = $progress;
        $pdf->total = $total;
        $pdf->setAuthor($user)
            ->setTitle($reportDraft->title)
            ->setSubject($reportDraft->title);

        $pdf->addCoverPage(
            $user->company,
            $reportDraft->title,
            $reportDraft->cover_image,
            $reportDraft->slogan,
            $reportDraft->cover_color,
            $reportDraft->cover_background
        );

        $pdf->setUserFooter($user, $reportDraft->footer);

        broadcast(new CompanyReportChanged($user, $reportDraft->id, 'Generating company page', $pdf->progress, $pdf->total));
        $pdf->progress++;
        $pdf->addCompanyPage(
            $user->company,
            collect($reportDraft->company_images ?: $user->company->images->take(3)->pluck('path'))->sortKeys(),
            data_get($reportDraft, 'company_details_config.images', 1),
            $reportDraft->company_name,
            $reportDraft->company_address,
            $reportDraft->company_about,
            $reportDraft->company_descriptions,
            $reportDraft->getCompanyDetails()
        );

        foreach ($reportDraft->reportSections as $section) {
            foreach ($section->pivot->content as $key => $item) {
                $section->$key = $item;
            }
            broadcast(new CompanyReportChanged($user, $reportDraft->id, 'Generating section ' . $section->template_title . ' page', $pdf->progress, $pdf->total));
            $pdf->progress++;
            $pdf->addSectionPage(
                $user->company,
                $section->main_image,
                $section->title,
                $section->subtitle,
                $section->about,
                $section->description,
                $section->details
            );
        }

        if ($reportDraft->add_map) {
            broadcast(new CompanyReportChanged($user, $reportDraft->id, 'Generating project map', $pdf->progress, $pdf->total));
            $pdf->progress++;
            $pdf->addProjectsMapPages($projects, $reportDraft->projects);
        }

        $pdf->addProjectPages($user->company, $projects, $reportDraft->projects);

        if ($reportDraft->add_org) {
            broadcast(new CompanyReportChanged($user, $reportDraft->id, 'Generating Org Chart', $pdf->progress, $pdf->total));
            $pdf->progress++;

            $orgChart = $user->company->orgCharts()->find((int) $reportDraft->orgchart_id);

            $pdf->addOrgChartPage($user->company, $orgChart, $reportDraft->orgchart_image, $reportDraft->org_title ?? '');
        }

        if ($reportDraft->add_team) {
             broadcast(new CompanyReportChanged($user, $reportDraft->id, 'Generating Team Section', $pdf->progress, $pdf->total));
            $pdf->progress++;
            $pdf->addTeamPage($user->company, $reportDraft);
        }

        if ($reportDraft->add_appendix) {
             broadcast(new CompanyReportChanged($user, $reportDraft->id, 'Generating Appendix Page', $pdf->progress, $pdf->total));
            $pdf->progress++;
            $pdf->addAppendixPage($user->company, $reportDraft);
        }

        broadcast(new CompanyReportChanged($user, $reportDraft->id, 'Generating last page', $pdf->progress, $pdf->total));
        $pdf->progress++;
        $pdf->addEndPage(
            $user->company,
            $reportDraft->final_page_image ?? $reportDraft->cover_image,
            $reportDraft->final_page_slogan ?? '',
            $reportDraft->final_page_address,
            $reportDraft->final_page_email,
            $reportDraft->final_page_color,
            $reportDraft->final_page_background
        );

        $pdf->setPoweredByFooter('Powered by ProjectMark');

        return $pdf;
    }

    /**
     * {@inheritdoc}
     */
    public function setUserFooter(User $user, array $footer = []): PdfContract
    {
        self::$pdf->setFooterFromUser($user, $footer);
        self::$pdf->setPrintFooter(true);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setPoweredByFooter(string $poweredByText): PdfContract
    {
        self::$pdf->setPoweredByFooter($poweredByText);
        self::$pdf->setPrintFooter(true);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addCompanyPage(
        Company $company,
        Collection $images,
        int $imageCount,
        string $name,
        string $address = null,
        string $companyAbout = null,
        array $descriptions = [],
        array $sections = []
    ): PdfContract {
        foreach ($descriptions as $index => $description) {
            $tmpImages = clone $images;
            self::$pdf->AddPage('P', 'A4');

            self::$pdf->SetTextColor(
                self::$pdf->rgbaColor('black')['r'],
                self::$pdf->rgbaColor('black')['g'],
                self::$pdf->rgbaColor('black')['b']
            );
            self::$pdf->SetAlpha(self::$pdf->rgbaColor('black')['a']);

            $galleryHeight = $this->addCompanyImages($imageCount, $tmpImages, 3);

            // Company header
            $fixH1spacing = [
                'h1' => [
                    0 => [
                        'h' => 0, 'n' => 0.75,
                    ],
                    1 => [
                        'h' => 0, 'n' => 0.05,
                    ],
                ],
            ];
            self::$pdf->setHtmlVSpace($fixH1spacing);

            self::$pdf->writeHTMLCell(
                0,
                0,
                $this->pageStartX,
                $galleryHeight,
                view('cms.report.partials.pdf.company-header', compact('company', 'name', 'address'))->render(),
                0,
                0,
                false,
                true,
                'L'
            );
            self::$pdf->Line(
                $this->pageStartX,
                $galleryHeight + $this->marginBottomLine,
                210 - 10,
                $galleryHeight + $this->marginBottomLine,
                [
                    'color' => [
                        self::$pdf->rgbaColor('gray')['r'],
                        self::$pdf->rgbaColor('gray')['g'],
                        self::$pdf->rgbaColor('gray')['b'],
                    ],
                ]
            );
            // Company About
            $about['width'] = 112;
            $about['x'] = 10;
            $view = $index === 0 ? 'cms.report.partials.pdf.company-about' : 'cms.report.partials.pdf.company-about-page';
            self::$pdf->writeHTMLCell(
                $about['width'] - 5,
                0,
                10,
                $galleryHeight + ($index === 0 ? $this->marginAfterBottomLine : 22),
                view($view, compact('company', 'companyAbout', 'description'))->render(),
                0,
                0,
                0,
                1,
                'L',
                1
            );
            // Company Features
            self::$pdf->writeHTMLCell(
                210 - ($about['width'] + $about['x']) - 5,
                0,
                $about['width'] + 5,
                $galleryHeight + $this->marginAfterBottomLine,
                view('cms.report.partials.pdf.features', compact('sections'))->render(),
                0,
                0,
                0,
                1,
                'L',
                1
            );

            self::$pdf->lastPage();
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addOrgChartPage(
        Company $company,
        Orgchart $orgchart,
        string $image,
        string $title
    ): PdfContract {
        self::$pdf->AddPage('P', 'A4');

        self::$pdf->SetTextColor(
            self::$pdf->rgbaColor('black')['r'],
            self::$pdf->rgbaColor('black')['g'],
            self::$pdf->rgbaColor('black')['b']
        );

        self::$pdf->setPrintHeader(false);
        self::$pdf->setPrintFooter(true);

        $slogan = null;
        $companyDetails = null;
        self::$pdf->writeHTMLCell(
            0,
            0,
            $this->pageStartX,
            $this->pageStartY,
            view(
                'cms.report.partials.pdf.orgchart-header',
                compact('company', 'orgchart')
            )->render(),
            0,
            0,
            false,
            true,
            'C',
            true
        );


        $galleryHeight = 0;
        $spacing = 3;

        $galleryHeight = 150;
        $imageSize = image_size($image);
        $imageLgW = $imageSize['width'];
        $imageLgH = $imageSize['height'];

        // =============
        // Org Chart Image
        // =============
        $pageWidth = self::$pdf->getPageWidth();
        $pageHeight = self::$pdf->getPageHeight();
        $widthRatio = $pageWidth / $imageLgW;
        $heightRatio = $pageHeight / $imageLgH;
        $ratio = $widthRatio > $heightRatio ? $heightRatio : $widthRatio;
        $canvasWidth = $imageLgW * $ratio;
        $canvasHeight = $imageLgH * $ratio;
        $marginX = ($pageWidth - $canvasWidth) / 2;
        $marginY = ($pageHeight - $canvasHeight) / 2;


        self::$pdf->Image(
            image_src($image, $imageLgW * 2, $imageLgH * 2),
            $marginX,
            $marginY,
            $canvasWidth,
            $canvasHeight
        );

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addSectionPage(
        Company $company,
        string $image,
        string $title,
        $subtitle = null,
        $pageAbout = '',
        $description = '',
        $details = ''
    ): PdfContract {
        self::$pdf->AddPage('P', 'A4');

        self::$pdf->SetTextColor(
            self::$pdf->rgbaColor('black')['r'],
            self::$pdf->rgbaColor('black')['g'],
            self::$pdf->rgbaColor('black')['b']
        );
        self::$pdf->SetAlpha(self::$pdf->rgbaColor('black')['a']);

        $galleryHeight = 150;
        $imageLgW = 675;
        $imageLgH = 520;
        // =============
        // First Image
        // =============
        $canvasX = $this->pageStartX;
        $canvasY = $this->pageStartY;

        self::$pdf->StartTransform();
        self::$pdf->Rect(
            $canvasX,
            $canvasY,
            self::$pdf->pixelsToUnits($imageLgW),
            self::$pdf->pixelsToUnits($imageLgH),
            'CNZ'
        );
        self::$pdf->Image(
            image_src($image, $imageLgW * 2, $imageLgH * 2),
            $canvasX,
            $canvasY,
            self::$pdf->pixelsToUnits($imageLgW),
            self::$pdf->pixelsToUnits($imageLgH)
        );
        self::$pdf->StopTransform();

        // Company header
        $fixH1spacing = [
            'h1' => [
                0 => [
                    'h' => 0, 'n' => 0.75,
                ],
                1 => [
                    'h' => 0, 'n' => 0.05,
                ],
            ],
        ];
        self::$pdf->setHtmlVSpace($fixH1spacing);

        self::$pdf->writeHTMLCell(
            0,
            0,
            $this->pageStartX,
            $galleryHeight,
            view('cms.report.partials.pdf.page-header', compact('title', 'subtitle'))->render(),
            0,
            0,
            false,
            true,
            'L'
        );
        self::$pdf->Line(
            $this->pageStartX,
            $galleryHeight + $this->marginBottomLine,
            210 - 10,
            $galleryHeight + $this->marginBottomLine,
            [
                'color' => [
                    self::$pdf->rgbaColor('gray')['r'],
                    self::$pdf->rgbaColor('gray')['g'],
                    self::$pdf->rgbaColor('gray')['b'],
                ],
            ]
        );
        // Company About
        $about['width'] = 112;
        $about['x'] = 10;
        self::$pdf->writeHTMLCell(
            $about['width'] - 5,
            0,
            10,
            $galleryHeight + $this->marginAfterBottomLine,
            view('cms.report.partials.pdf.page-about', compact('pageAbout', 'description'))->render(),
            0,
            0,
            0,
            1,
            'L',
            1
        );
        // Company Features
        self::$pdf->writeHTMLCell(
            210 - ($about['width'] + $about['x']) - 5,
            0,
            $about['width'] + 5,
            $galleryHeight + $this->marginAfterBottomLine,
            view('cms.report.partials.pdf.page-details', compact('details'))->render(),
            0,
            0,
            0,
            1,
            'L',
            1
        );

        self::$pdf->lastPage();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addCoverPage(
        Company $company,
        string $title,
        string $coverImage,
        string $slogan = null,
        string $coverTextColor = null,
        string $coverBackgroundColor = null
    ): PdfContract {
        self::$pdf->AddPage('P', 'A4');

        self::$pdf->setPrintHeader(false);
        self::$pdf->setPrintFooter(false);

        $this->_addCover($company, $coverImage, $title, $slogan, [], $coverTextColor, $coverBackgroundColor);

        self::$pdf->lastPage();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addEndPage(
        Company $company,
        string $coverImage = null,
        string $slogan = '',
        string $address = null,
        string $email = null,
        string $coverTextColor = null,
        string $coverBackgroundColor = null
    ): PdfContract {
        self::$pdf->AddPage('P', 'A4');

        self::$pdf->setPrintHeader(false);
        self::$pdf->setPrintFooter(false);

        $this->_addCover($company, $coverImage, $slogan, $slogan, [
            'email' => $email,
            'address' => $address,
        ], $coverTextColor, $coverBackgroundColor);

        self::$pdf->lastPage();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addProjectPage(
        Company $company,
        Project $project,
        array $reportProject = []
    ): PdfContract {
        if (Arr::has($reportProject, 'descriptions')) {
            $descriptions = $reportProject['descriptions'];
        } else {
            $description = $project->pivot->description ?: $project->description;
            $countDescription = strlen(strip_tags($description));
            if ($countDescription < 1100) {
                $descriptions = [
                    $description,
                ];
            } else {
                $dom = new DOMDocument();
                $descriptions = [];
                $total = 0;
                $builder = '';
                $description = mb_convert_encoding($description, 'HTML-ENTITIES', 'UTF-8');
                $dom->loadHTML($description);
                foreach ($dom->getElementsByTagName('p') as $index => $node) {
                    /** @var \DOMNode $node */
                    $nodeWithoutHtml = strip_tags($node->textContent);
                    $limit = $index === 0 ? 1100 : 1200;

                    if ($total + strlen($nodeWithoutHtml) < $limit) {
                        $builder .= $dom->saveXML($node);
                        $total += strlen($nodeWithoutHtml);
                    } elseif ($total === 0) {
                        $descriptions[] = $dom->saveXML($node);
                    } else {
                        if (empty($builder)) {
                            $builder = $dom->saveXML($node);
                        }
                        $descriptions[] = $builder;
                        $descriptions[] = $dom->saveXML($node);
                        $total = 0;
                        $builder = '';
                    }
                }
                if (!empty($builder)) {
                    $descriptions[] = $builder;
                }
            }
        }

        // Project images
        $images =  collect(Arr::get($reportProject, 'images', [
            Arr::get($reportProject, 'image', Arr::get($project, 'image_path.companies.' . $company->id, ''))
        ]))->sortKeys();
        $imagesCount = (int) Arr::get($reportProject, 'details_config.images', 1);
        foreach ($descriptions as $index => $description) {
            $tmpImages = clone $images;

            self::$pdf->AddPage('P', 'A4');
            self::$pdf->setPrintHeader(false);
            self::$pdf->setPrintFooter(true);

            self::$pdf->SetTextColor(
                self::$pdf->rgbaColor('black')['r'],
                self::$pdf->rgbaColor('black')['g'],
                self::$pdf->rgbaColor('black')['b']
            );
            self::$pdf->SetAlpha(self::$pdf->rgbaColor('black')['a']);

            $galleryHeight = 0;
            $spacing = 3;

            if ($imagesCount === 1) {
                $galleryHeight = 150;
                $imageLgW = 675;
                $imageLgH = 520;
                // =============
                // First Image
                // =============
                $image = $tmpImages->shift();
                $canvasX = $this->pageStartX;
                $canvasY = $this->pageStartY;

                self::$pdf->StartTransform();
                self::$pdf->Rect(
                    $canvasX,
                    $canvasY,
                    self::$pdf->pixelsToUnits($imageLgW),
                    self::$pdf->pixelsToUnits($imageLgH),
                    'CNZ'
                );
                self::$pdf->Image(
                    image_src($image, $imageLgW * 2, $imageLgH * 2),
                    $canvasX,
                    $canvasY,
                    self::$pdf->pixelsToUnits($imageLgW),
                    self::$pdf->pixelsToUnits($imageLgH)
                );
                self::$pdf->StopTransform();
            } elseif ($imagesCount === 2) {
                $galleryHeight = 75;
                $imageLgW = 387;
                $imageLgH = 255;

                $imageSmW = 275;
                $imageSmH = 255;
                // =============
                // First Image
                // =============
                $image = $tmpImages->shift();
                $canvasX = $this->pageStartX;
                $canvasY = $this->pageStartY;

                self::$pdf->StartTransform();
                self::$pdf->Rect(
                    $canvasX,
                    $canvasY,
                    self::$pdf->pixelsToUnits($imageLgW),
                    self::$pdf->pixelsToUnits($imageLgH),
                    'CNZ'
                );
                self::$pdf->Image(
                    image_src($image, $imageLgW * 2, $imageLgH * 2),
                    $canvasX,
                    $canvasY,
                    self::$pdf->pixelsToUnits($imageLgW),
                    self::$pdf->pixelsToUnits($imageLgH)
                );
                self::$pdf->StopTransform();

                // =============
                // Second Image
                // =============
                $image = $tmpImages->shift();
                $canvasX = $this->pageStartX + self::$pdf->pixelsToUnits($imageLgW) + $spacing;
                $canvasY = $this->pageStartY;

                self::$pdf->StartTransform();
                self::$pdf->Rect(
                    $canvasX,
                    $canvasY,
                    self::$pdf->pixelsToUnits($imageSmW),
                    self::$pdf->pixelsToUnits($imageSmH),
                    'CNZ'
                );
                self::$pdf->Image(
                    image_src($image, $imageSmW * 2, $imageSmH * 2),
                    $canvasX,
                    $canvasY,
                    self::$pdf->pixelsToUnits($imageSmW),
                    self::$pdf->pixelsToUnits($imageSmH)
                );
                self::$pdf->StopTransform();
            } elseif ($imagesCount >= 3) {
                $galleryHeight = 150;
                $imageLgW = 390;
                $imageLgH = 520;
                $imageSmW = 275;
                $imageSmH = 255;
                // =============
                // First Image
                // =============
                $image = $tmpImages->shift();
                $canvasX = $this->pageStartX;
                $canvasY = $this->pageStartY;

                self::$pdf->StartTransform();
                self::$pdf->Rect(
                    $canvasX,
                    $canvasY,
                    self::$pdf->pixelsToUnits($imageLgW),
                    self::$pdf->pixelsToUnits($imageLgH),
                    'CNZ'
                );
                self::$pdf->Image(
                    image_src($image, $imageLgW * 2, $imageLgH * 2),
                    $canvasX,
                    $canvasY,
                    self::$pdf->pixelsToUnits($imageLgW),
                    self::$pdf->pixelsToUnits($imageLgH)
                );
                self::$pdf->StopTransform();

                // =============
                // Second Image
                // =============
                $image = $tmpImages->shift();
                $canvasX = $this->pageStartX + self::$pdf->pixelsToUnits($imageLgW) + $spacing;
                $canvasY = $this->pageStartY;

                self::$pdf->StartTransform();
                self::$pdf->Rect(
                    $canvasX,
                    $canvasY,
                    self::$pdf->pixelsToUnits($imageSmW),
                    self::$pdf->pixelsToUnits($imageSmH),
                    'CNZ'
                );
                self::$pdf->Image(
                    image_src($image, $imageSmW * 2, $imageSmH * 2),
                    $canvasX,
                    $canvasY,
                    self::$pdf->pixelsToUnits($imageSmW),
                    self::$pdf->pixelsToUnits($imageSmH)
                );
                self::$pdf->StopTransform();

                // =============
                // Third Image
                // =============
                $image = $tmpImages->shift();
                $canvasX = $this->pageStartX + self::$pdf->pixelsToUnits($imageLgW) + $spacing;
                $canvasY = $this->pageStartY + self::$pdf->pixelsToUnits($imageSmH) + $spacing;

                self::$pdf->StartTransform();
                self::$pdf->Rect(
                    $canvasX,
                    $canvasY,
                    self::$pdf->pixelsToUnits($imageSmW),
                    self::$pdf->pixelsToUnits($imageSmH),
                    'CNZ'
                );
                self::$pdf->Image(
                    image_src($image, $imageSmW * 2, $imageSmH * 2),
                    $canvasX,
                    $canvasY,
                    self::$pdf->pixelsToUnits($imageSmW),
                    self::$pdf->pixelsToUnits($imageSmH)
                );
                self::$pdf->StopTransform();
            }

            // Project header
            $fixH1spacing = [
                'h1' => [
                    0 => [
                        'h' => 0, 'n' => 0.75,
                    ],
                    1 => [
                        'h' => 0, 'n' => 0.05,
                    ],
                ],
            ];
            self::$pdf->setHtmlVSpace($fixH1spacing);

            $name = Arr::get($reportProject, 'name', $project->pivot->name ?: $project->name);
            $address = Arr::get($reportProject, 'address', $project->address);
            self::$pdf->writeHTMLCell(
                0,
                0,
                $this->pageStartX,
                $galleryHeight,
                view('cms.report.partials.pdf.project-header', compact('name', 'address'))->render(),
                0,
                0,
                false,
                true,
                'L'
            );

            self::$pdf->Line(
                $this->pageStartX,
                $galleryHeight + $this->marginBottomLine,
                210 - 10,
                $galleryHeight + $this->marginBottomLine,
                [
                    'color' => [
                        self::$pdf->rgbaColor('gray')['r'],
                        self::$pdf->rgbaColor('gray')['g'],
                        self::$pdf->rgbaColor('gray')['b'],
                    ],
                ]
            );
            // Project About
            $projectAbout = Arr::get($reportProject, 'about', str_replace('{projectName}', $project->pivot->name ?: $project->name, __('About {projectName}')));
            $about['width'] = 112;
            $about['x'] = 10;
            $view = $index === 0 ? 'cms.report.partials.pdf.project-about' : 'cms.report.partials.pdf.project-about-page';
            self::$pdf->writeHTMLCell(
                $about['width'] - 5,
                0,
                10,
                $galleryHeight + ($index === 0 ? $this->marginAfterBottomLine : 22),
                view($view, compact('project', 'projectAbout', 'description'))->render(),
                0,
                0,
                0,
                1,
                'L',
                1
            );
            // Project Features
            $sections = Arr::get($reportProject, 'sections', []);
            $customSections = Arr::get($reportProject, 'custom_details', []);
            
            $customSections = array_map(static function($section){
                $content = $section['content'];
                $section['value'] = $content;
                unset($section['content']);
                return $section;
               
            },$customSections);

            $allSections = array_merge($sections, $customSections);

            self::$pdf->writeHTMLCell(
                210 - ($about['width'] + $about['x']) - 5,
                0,
                $about['width'] + 5,
                $galleryHeight + $this->marginAfterBottomLine,
                view('cms.report.partials.pdf.features', compact('sections','customSections','allSections'))->render(),
                0,
                0,
                0,
                1,
                'L',
                1
            );

            self::$pdf->lastPage();
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addProjectsMapPages($projects, array $reportProjects): PdfContract
    {
        // We are going to show 9 projects for each page.
        $projectsGroups = $projects->chunk(9);
        foreach ($projectsGroups as $projectsGroup) {
            $this->_addProjectsMapPage($projectsGroup, $reportProjects);
        }

        return $this;
    }

    /**
     * Ads cover image to cover page.
     *
     * @param string $image
     * @param string $coverBackgroundColor
     */
    private function _addCoverImage(string $image, string $coverBackgroundColor = null): void
    {
        if ($coverBackgroundColor) {
            // Background color for cover.
            $backgroundRgba = $this->_getRgba($coverBackgroundColor);
            self::$pdf->SetFillColor($backgroundRgba['r'], $backgroundRgba['g'], $backgroundRgba['b']);
            self::$pdf->SetAlpha($backgroundRgba['a']);
            self::$pdf->Rect(
                0,
                0,
                self::$pdf->getPageWidth(),
                self::$pdf->getPageHeight(),
                'F'
            );
            self::$pdf->SetAlpha(1);
        }

        self::$pdf->Image(
            image_src($image, $this->coverImageWidthPx * 2, $this->coverImageHeightPx * 2),
            0,
            0,
            self::$pdf->pixelsToUnits($this->coverImageWidthPx),
            self::$pdf->pixelsToUnits($this->coverImageHeightPx)
        );
    }

    /**
     * Adds company details to cover page.
     *
     * @param Company     $company
     * @param string      $title
     * @param array       $companyDetails
     * @param string|null $slogan
     * @param string|null $textColor
     */
    private function _addCompanyDetails(
        Company $company,
        string $title,
        array $companyDetails,
        string $slogan = null,
        string $textColor = null
    ): void {
        if ($textColor) {
            $textColorRgba = $this->_getRgba($textColor);
            self::$pdf->SetFontSize(10);
            self::$pdf->SetTextColor($textColorRgba['r'], $textColorRgba['g'], $textColorRgba['b']);
        }

        self::$pdf->writeHTMLCell(
            0,
            0,
            $this->pageStartX,
            (self::$pdf->pixelsToUnits($this->coverImageHeightPx)) + 10,
            view(
                'cms.report.partials.pdf.company-logo',
                compact('company', 'title', 'slogan', 'companyDetails')
            )->render(),
            0,
            0,
            false,
            true,
            'L'
        );
    }

    /**
     * @param Company     $company
     * @param string      $image
     * @param string      $title
     * @param string|null $slogan
     * @param array       $companyDetails
     * @param string|null $coverTextColor
     * @param string|null $coverBackgroundColor
     */
    private function _addCover(
        Company $company,
        string $image,
        string $title = '',
        string $slogan = null,
        array $companyDetails = [],
        string $coverTextColor = null,
        string $coverBackgroundColor = null
    ): void {
        $this->_addCoverImage($image, $coverBackgroundColor);
        $this->_addCompanyDetails($company, $title, $companyDetails, $slogan, $coverTextColor);
    }

    /**
     * Add projects map page.
     *
     * @param Project[]|\Illuminate\Support\Collection $projects
     * @param array                                    $reportProjects
     *
     * @return PdfContract
     */
    private function _addProjectsMapPage($projects, array $reportProjects): PdfContract
    {
        self::$pdf->AddPage('P', 'A4');

        self::$pdf->setPrintHeader(false);
        self::$pdf->setPrintFooter(true);

        self::$pdf->SetTextColor(
            self::$pdf->rgbaColor('black')['r'],
            self::$pdf->rgbaColor('black')['g'],
            self::$pdf->rgbaColor('black')['b']
        );
        self::$pdf->SetAlpha(self::$pdf->rgbaColor('black')['a']);
        self::$pdf->writeHTML('<h1 style="text-align: center">Our Presence</h1>');
        $this->mapImageTitleHeight = self::$pdf->pixelsToUnits($this->mapImageTitleHeightPx);

        $this->_addProjectMark($projects);

        $mapImageYunit = self::$pdf->pixelsToUnits($this->mapImageHeightPx);
        $thumbWunit = self::$pdf->pixelsToUnits($this->thumbWidthPx);
        $thumbHunit = self::$pdf->pixelsToUnits($this->thumbHeightPx);

        // Y value for each thumbnail.
        $incrementYunit = $this->pageStartY + $mapImageYunit + $this->thumbCellSpacing + $this->mapImageTitleHeight;

        // We are going to paint projects in rows of 3.
        $projectsRows = $projects->chunk(3);
        foreach ($projectsRows as $projectsRow) {
            $incrementXunit = 0;
            foreach ($projectsRow as $project) {
                $this->_addProjectMarkThumb(
                    $this->pageStartX + $incrementXunit,
                    $incrementYunit,
                    $thumbWunit,
                    $thumbHunit,
                    $project,
                    Arr::get($reportProjects, $project->id, [])
                );
                $incrementXunit += $thumbWunit + $this->thumbCellSpacing;
            }
            $incrementYunit += $thumbHunit + $this->thumbCellSpacing;
        }

        self::$pdf->lastPage();

        return $this;
    }

    /**
     * Add Google Map's static image with project markers.
     *
     * @param Project[]|\Illuminate\Support\Collection $projects
     */
    private function _addProjectMark($projects): void
    {
        $map = new GoogleStaticMap(config('googlemaps.key', ''));
        $map->setMapType(MapType::RoadMap)
            ->setZoom(0)
            ->setScale(2)
            ->setSize($this->mapImageWidthPx, $this->mapImageHeightPx)
            ->setFormat('png32');

        foreach ($projects as $project) {
            if (empty($project->latitude) || empty($project->longitude)) {
                continue;
            }
            $map->addMarkerLatLngWithIcon(
                $project->latitude,
                $project->longitude,
                'https://projectmark.com/images/marker-idle.png'
            );
        }
        $image = $map->make();
        self::$pdf->Image(
            $image,
            $this->pageStartX,
            $this->pageStartY + $this->mapImageTitleHeight,
            self::$pdf->pixelsToUnits($this->mapImageWidthPx),
            self::$pdf->pixelsToUnits($this->mapImageHeightPx)
        );
    }

    /**
     * Add a project thumbnail.
     *
     * @param int     $x
     * @param int     $y
     * @param int     $w
     * @param int     $h
     * @param Project $project
     * @param array   $reportProject
     */
    private function _addProjectMarkThumb(int $x, int $y, int $w, int $h, Project $project, array $reportProject = []): void
    {
        self::$pdf->StartTransform();

        // Mask to hide overflowing data
        self::$pdf->Rect($x, $y, $w, $h, 'CNZ');

        // Project image
        $image = $project->images->first();
        if (Arr::get($reportProject, 'image')) {
            $imageUrl = $reportProject['image'];
        } else {
            $imageUrl = !$image instanceof Image || empty($image->path) ? asset('/images/no-image.jpg') : $image->path;
        }

        self::$pdf->Image(
            image_src($imageUrl, $this->thumbImageWidthPx * 2, $this->thumbImageHeightPx * 2, Arr::get($reportProject, 'name', $project->name)),
            $x,
            $y,
            self::$pdf->pixelsToUnits($this->thumbImageWidthPx),
            self::$pdf->pixelsToUnits($this->thumbImageHeightPx)
        );

        // Project texts
        $name = Arr::get($reportProject, 'name');
        $address = Arr::get($reportProject, 'address');
        self::$pdf->writeHTMLCell(
            self::$pdf->pixelsToUnits($this->thumbWidthPx),
            0,
            $x,
            $y + self::$pdf->pixelsToUnits($this->thumbImageHeightPx) + 2,
            view('cms.report.partials.pdf.project-thumbnail', compact('project', 'name', 'address'))->render()
        );

        self::$pdf->StopTransform();
    }

    public function addAppendixPage(Company $company, ReportDraft $reportDraft): PdfContract
    {

        self::$pdf->AddPage('P', 'A4');

        self::$pdf->SetTextColor(
            self::$pdf->rgbaColor('black')['r'],
            self::$pdf->rgbaColor('black')['g'],
            self::$pdf->rgbaColor('black')['b']
        );

        self::$pdf->setPrintHeader(false);
        self::$pdf->setPrintFooter(true);

    
        self::$pdf->SetAlpha(self::$pdf->rgbaColor('black')['a']);
        self::$pdf->writeHTML('<span style="text-align: left; font-size:30px; font-weight:bold; line-height:25px;">'.$reportDraft->appendix_title.'</span>');
        self::$pdf->Ln(5);
        self::$pdf->writeHTML($reportDraft->appendix_description);
        self::$pdf->Ln(5);
        
        foreach($reportDraft->appendices as $appendice){
            self::$pdf->Ln(3);
            self::$pdf->writeHTML('<span style="text-align: left; font-size:18px; font-weight:bold;">'.$appendice['title'].'</span>');
            self::$pdf->Ln(3);
            
            self::$pdf->writeHTMLCell(
                0,
                0,
                '' ,
                '',
                $appendice['description'],
                0,
                1,
                false,
                true,
                'L'
            );
        }    

        return $this;
    }

    public function addTeamPage(Company $company, ReportDraft $reportDraft): PdfContract
    {

        self::$pdf->AddPage('P', 'A4');

        self::$pdf->SetTextColor(
            self::$pdf->rgbaColor('black')['r'],
            self::$pdf->rgbaColor('black')['g'],
            self::$pdf->rgbaColor('black')['b']
        );

        $canvasX = $this->pageStartX;
        $canvasY = $this->pageStartY;

        self::$pdf->setPrintHeader(false);
        self::$pdf->setPrintFooter(true);

                           
        self::$pdf->SetAlpha(self::$pdf->rgbaColor('black')['a']);
        self::$pdf->writeHTML('<span style="text-align: left; font-size:24px; font-weight:bold; line-height:25px;">'.$reportDraft->team_title.'</span>');
        
        self::$pdf->Ln(4);

        
        $pageWidth = 210;
        $pageHeight = 297;

        $startPointX = 30;
        $startPointY = 45;

        $titleCellSpacing = 14;
        $descriptionCellSpacing = 17;

        $imageNull = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2OTApLCBxdWFsaXR5ID0gOTAK/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU/8AAEQgAIwAjAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+6s0ZqjrOs2Ph7TZr/UbhLW0hALyNk9TgAAckkkAAZJJAHNS6fqFrqtlBeWcyXNrMoeOWM5Vgadna4rq9izRScUcUhi80UcUUAcX8Uvh+3j7RYo7a4SDUrMyS2n2nc1sztE8ZEqAjcMOcHqDyM8gt+Hvw1i8FeGo7G5v59Rv5Jpbu5u9xRTNKQZPLQcImQMKPfuTXbZ+tJk+9a+0lycl9COSPNzdTMGgW/l7HlnlXcG/eSknP1qS10v7FOHhnYoRtZZsuSM54OePyq/k+9Ln61ndlWEyPailz9aKQw70naiigA7Up/rRRQAUUUUAf//Z';
        
        $spaceW = 100;

        $incrementX = 0;
        $incrementY = 0;
        foreach(collect($reportDraft->teams) as $team){
            
            self::$pdf->writeHTML('<h4 style="text-align: left; font-size:18px; font-weight:bold; line-height:22px;">'.$team['title'].'</h4>',true);
 
            $membersIds = $team['members'];
        
            $members = $company->members()
                           ->whereIn('company_members.id', $membersIds)->get();

            $incrementY = $incrementY + $startPointY;
            $reAdaptImageInCircleY = 0;
            
            $membersRows = $members->chunk(2);
            
            foreach($membersRows as $membersRowsindex => $membersRow)
            {
                
                $incrementX = $startPointX;
                foreach ($membersRow as $memberIndex => $member) {
                
                    
                    $image = $member->image ?? $imageNull;

    
                    $imageLgW = 100;
                    $imageLgH = 100;
    
                    self::$pdf->Image(image_src($image,$imageLgW-50, $imageLgH-50), $incrementX-5, $incrementY-10, 0, 0);
                     
                    self::$pdf->writeHTMLCell(
                        0,
                        0,
                        $incrementX + $titleCellSpacing,
                        $incrementY - $descriptionCellSpacing,
                        '<div style="width: 100%;  font-size:14px;">
                            <h4 style="line-height:1;font-weight:bold;">'.$member->name.'</h4>
                            <span>'.$member->role.'</span>
                        </div>',
                        0,
                        1
                    );
                    
                    $incrementX += $spaceW;
                }
                $incrementY = $incrementY + 25;
                $reAdaptImageInCircleY = $reAdaptImageInCircleY + 15;
                
            }
            self::$pdf->Ln(10);
            $teamTitleCellSpacing = -33;
            $incrementY += $teamTitleCellSpacing;
   
        }
        self::$pdf->lastPage();

        return $this;

    }
}
