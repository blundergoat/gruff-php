<?php

function archiveOpen(): void
{
    $zip = new ZipArchive();
    $zip->open($_GET['x']);
}

function ormLoad(object $orm): void
{
    $orm->load($_GET['x']);
}

function builderXml(object $builder): void
{
    $builder->xml($_GET['x']);
}

function domLoad(): void
{
    $doc = new DOMDocument();
    $doc->load($_GET['x']);
}

function domLoadXmlInline(): void
{
    (new DOMDocument())->loadXML($_GET['x']);
}

function readerOpen(): void
{
    $reader = new XMLReader();
    $reader->open($_GET['x']);
}

function readerStaticXml(): void
{
    XMLReader::xml($_GET['x']);
}

function domLoadSafe(): void
{
    $doc = new DOMDocument();
    $doc->load($_GET['x'], LIBXML_NONET);
}

function conditionallyRebound(bool $useBuilder, object $builder): void
{
    $document = new DOMDocument();
    if ($useBuilder) {
        $document = $builder;
    }

    $document->loadXML($_GET['xml']);
}

function conditionallyConstructed(bool $parseXml, object $reader): void
{
    $target = $reader;
    if ($parseXml) {
        $target = new DOMDocument();
    }

    $target->loadXML($_GET['xml']);
}

function reboundOnSinkPath(bool $useBuilder, object $builder): void
{
    $document = new DOMDocument();
    if ($useBuilder) {
        $document = $builder;
        $document->loadXML($_GET['xml']);
    }
}
