<?php
declare(strict_types=1);

namespace Tum\Installer\Domain\Model;

enum SetupType: string
{
    case SETUP1 = 'Setup1';
    case SETUP3 = 'Setup3';
    case ARCHIV = 'Archiv';
    case STANDALONE = 'Standalone';
}