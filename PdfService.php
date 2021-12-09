<?php

namespace App\Services\PDF;

use Str;
use Arr;
use Cache;
use App\Models\User;
use App\Models\Company;
use Illuminate\Http\UploadedFile;
use App\Contracts\PDF\PdfContract;
use Illuminate\Support\Collection;
use App\Events\CompanyReportChanged;
use mastani\GoogleStaticMap\MapType;
use App\Contracts\File\FileManagerContract;
use mastani\GoogleStaticMap\GoogleStaticMap;

abstract class PdfService implements PdfContract
{
    /** @var \TCPDF */
    protected static $pdf;

    protected $pageStartX = 10;
    protected $pageStartY = 10;

    protected $mapImageWidthPx = 676;
    protected $mapImageHeightPx = 268; // 255
    protected $mapImageTitleHeight = 60;
    protected $mapImageTitleHeightPx = 60;

    protected $coverImageWidthPx = 744;
    protected $coverImageHeightPx = 685;

    protected $thumbWidthPx = 216;
    protected $thumbHeightPx = 195;
    protected $thumbImageWidthPx = 216;
    protected $thumbImageHeightPx = 136;
    protected $thumbCellSpacing = 4;
    protected $marginBottomLine = 26;
    protected $marginAfterBottomLine = 29;

    /**
     * @var \App\Models\User
     */
    protected $user;

    /**
     * @var int
     */
    protected $progress;

    /**
     * @var int
     */
    protected $total;

    /**
     * @var \App\Models\ReportDraft
     */
    protected $reportDraft;

    /**
     * {@inheritdoc}
     */
    public function generate(string $filename): string
    {
        broadcast(new CompanyReportChanged($this->user, $this->reportDraft->id, 'Saving generated PDF', $this->progress, $this->total));
        $this->progress++;
        $tmpHandle = tmpfile();
        fwrite($tmpHandle, self::$pdf->Output($filename, 'S'));
        $meta = stream_get_meta_data($tmpHandle);
        $tmpFilename = $meta['uri'];

        $file = new UploadedFile($tmpFilename, $filename);

        /** @var FileManagerContract $fileManager */
        $fileManager = app()->make(FileManagerContract::class);
        $addedFile = $fileManager->addFile($file, '/reports');
        fclose($tmpHandle);
        if (!empty($this->reportDraft->report_url) && $fileManager->existsUrl($this->reportDraft->report_url)) {
            $fileManager->removeFileWithUrl($this->reportDraft->report_url);
        }
        $this->reportDraft = $this->reportDraft->fresh();
        $this->reportDraft->report_url = $addedFile;
        $this->reportDraft->random_key = random_int(1, 99999);
        $this->reportDraft->save();

        Cache::forget($this->reportDraft->getCacheShareKey());

        return $addedFile;
    }

    /**
     * {@inheritdoc}
     */
    public function generatePdf(string $filename): string
    {
        return self::$pdf->Output($filename, 'I');
    }

    /**
     * {@inheritdoc}
     */
    public static function generateMap(Company $company, array $selectedProjects): string
    {
        $projects = $company->projects()
            ->whereIn('projects.id', $selectedProjects)
            ->setEagerLoads(['sectors' => static function () {}, 'certificates' => static function () {}])
            ->get()->unique('id');

        $map = new GoogleStaticMap(config('googlemaps.key', ''));
        $map->setMapType(MapType::RoadMap)
            ->setZoom(0)
            ->setScale(2)
            ->setSize(1240, 1754)
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

        return $map->make();
    }

    /**
     * @param User $user
     *
     * @return PdfContract
     */
    protected function setAuthor(User $user): PdfContract
    {
        self::$pdf->SetAuthor($user->name);

        return $this;
    }

    /**
     * @param string $title
     *
     * @return PdfContract
     */
    protected function setTitle(string $title): PdfContract
    {
        self::$pdf->SetTitle($title);

        return $this;
    }

    /**
     * @param string $title
     *
     * @return PdfContract
     */
    protected function setSubject(string $title): PdfContract
    {
        self::$pdf->SetSubject($title);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addProjectPages(Company $company, Collection $projects, array $reportProjects): PdfContract
    {
        foreach ($projects as $project) {
            broadcast(new CompanyReportChanged($this->user, $this->reportDraft->id, 'Generating project page: ' . $project->name, $this->progress, $this->total));
            $this->progress++;
            $reportProject = Arr::get($reportProjects, $project->id, []);
            $reportProject['sections'] = [];
            foreach (Arr::get($reportProject, 'details_config', []) as $section => $enabled) {
                if ($enabled && $section !== 'images') {
                    $reportProject['sections'][$section] = [
                        'title' => Arr::get($reportProject, $section . '_title'),
                        'value' => Arr::get($reportProject, $section),
                    ];
                }
            }
            $this->addProjectPage($company, $project, $reportProject);
        }

        return $this;
    }

    /**
     * Add the company images to PDF
     *
     * @param int                            $imageCount
     * @param \Illuminate\Support\Collection $tmpImages
     * @param int                            $spacing
     *
     * @return int
     */
    protected function addCompanyImages(int $imageCount, Collection $tmpImages, int $spacing): int
    {
        $galleryHeight = 0;

        if ($imageCount === 1) {
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
        } elseif ($imageCount === 2) {
            $galleryHeight = 75;
            $imageLgW = 387;
            $imageLgH = 255;

            $imageSmW = 280;
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
        } elseif ($imageCount >= 3) {
            $galleryHeight = 150;

            $imageLgW = 675;
            $imageLgH = 255;

            $imageMdW = 390;
            $imageMdH = 255;

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
            $canvasX = $this->pageStartX;
            $canvasY = $this->pageStartY + self::$pdf->pixelsToUnits($imageLgH) + $spacing;

            self::$pdf->StartTransform();
            self::$pdf->Rect(
                $canvasX,
                $canvasY,
                self::$pdf->pixelsToUnits($imageMdW),
                self::$pdf->pixelsToUnits($imageMdH),
                'CNZ'
            );
            self::$pdf->Image(
                image_src($image, $imageMdW * 2, $imageMdH * 2),
                $canvasX,
                $canvasY,
                self::$pdf->pixelsToUnits($imageMdW),
                self::$pdf->pixelsToUnits($imageMdH)
            );
            self::$pdf->StopTransform();

            // =============
            // Third Image
            // =============
            $image = $tmpImages->shift();
            $canvasX = $this->pageStartX + self::$pdf->pixelsToUnits($imageMdW) + $spacing;
            $canvasY = $this->pageStartY + self::$pdf->pixelsToUnits($imageLgH) + $spacing;

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

        return $galleryHeight;
    }

    /**
     * Add the project images to PDF
     *
     * @param int                            $imagesCount
     * @param \Illuminate\Support\Collection $tmpImages
     * @param int                            $spacing
     *
     * @return int
     */
    protected function addProjectImages(int $imagesCount, Collection $tmpImages, int $spacing): int
    {
        $galleryHeight = 0;

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

        return $galleryHeight;
    }

    /**
     * Get the RGBA in an array
     *
     * @param string $colorString
     *
     * @return array|string
     */
    protected function _getRgba(string $colorString)
    {
        $includeAlpha = Str::startsWith($colorString, 'rgba(');
        $color = trim($colorString, 'rgba()');
        $color = str_replace(' ', '', $color);
        $color = explode(',', $color);

        if (!$includeAlpha) {
            $color[] = '1';
        }

        return array_combine(['r', 'g', 'b', 'a'], $color);
    }
}
