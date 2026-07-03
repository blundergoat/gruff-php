<?php

declare(strict_types=1);

namespace Fixtures\Security\ArchiveSourceTaint;

function cleanLocalExtraction(): void
{
    $zip = new \ZipArchive();
    $zip->open('/var/data/fixed.zip');
    $zip->extractTo('/srv/files');
}

function taintedDestination(): void
{
    $zip = new \ZipArchive();
    $zip->open('/var/data/fixed.zip');
    $zip->extractTo($_GET['dest']);
}

function taintedEntries(): void
{
    $zip = new \ZipArchive();
    $zip->open('/var/data/fixed.zip');
    $zip->extractTo('/srv/files', [$_POST['entry']]);
}

function taintedUploadSource(): void
{
    $zip = new \ZipArchive();
    $zip->open($_FILES['archive']['tmp_name']);
    $zip->extractTo('/srv/files');
}

function cleanReopenClearsSource(): void
{
    $zip = new \ZipArchive();
    $zip->open($_FILES['archive']['tmp_name']);
    $zip->open('/var/data/fixed.zip');
    $zip->extractTo('/srv/files');
}

function reassignmentClearsSource(): void
{
    $zip = new \ZipArchive();
    $zip->open($_FILES['archive']['tmp_name']);
    $zip = new \ZipArchive();
    $zip->extractTo('/srv/files');
}

function taintedPharSource(): void
{
    $phar = new \PharData($_FILES['bundle']['tmp_name']);
    $phar->extractTo('/srv/files');
}

function conditionalCleanReopenKeepsSourceTaint(bool $useSafeArchive): void
{
    $zip = new \ZipArchive();
    $zip->open($_FILES['archive']['tmp_name']);

    if ($useSafeArchive) {
        $zip->open('/var/data/fixed.zip');
    }

    $zip->extractTo('/srv/files');
}

function cleanReopenOnSinkPathStaysClean(bool $extractNow): void
{
    $zip = new \ZipArchive();
    $zip->open($_FILES['archive']['tmp_name']);

    if ($extractNow) {
        $zip->open('/var/data/fixed.zip');
        $zip->extractTo('/srv/files');
    }
}

function conditionalTaintedOpenFlags(bool $useUpload): void
{
    $zip = new \ZipArchive();
    $zip->open('/var/data/fixed.zip');

    if ($useUpload) {
        $zip->open($_FILES['archive']['tmp_name']);
    }

    $zip->extractTo('/srv/files');
}
