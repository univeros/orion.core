<?php

declare(strict_types=1);

namespace Orion\Projection;

use Orion\Write\Document;

/**
 * Entity to document (v1 §13): an application-registered class, named by the manifest's `source.projector`, so the
 * one place application logic lives in indexing is real code the application tests. The document's version is the
 * outbox offset the application records it at, for lossless replay.
 */
interface Projector
{
    public function project(object $entity): Document;
}
