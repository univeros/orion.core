<?php

declare(strict_types=1);

namespace Orion\Schema;

enum VectorMetric: string
{
    case Cosine = 'cosine';
    case DotProduct = 'dot_product';
    case Euclidean = 'euclidean';
}
