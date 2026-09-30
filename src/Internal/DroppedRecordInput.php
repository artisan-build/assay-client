<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Internal;

use ArtisanBuild\AssayClient\RecordInput;

/** @internal Routes a source projection failure through the recorder's transport-drop path. */
final readonly class DroppedRecordInput implements RecordInput {}
