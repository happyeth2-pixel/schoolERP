<?php

namespace Tests\Unit;

use App\Support\VirusScan;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class VirusScanTest extends TestCase
{
    public function test_it_is_a_no_op_when_disabled_which_is_the_default(): void
    {
        config(['security.clamav_enabled' => false]);

        $file = UploadedFile::fake()->create('receipt.pdf', 10);

        $this->assertNull(VirusScan::problem($file));
    }

    public function test_enabling_it_without_a_real_binary_fails_open_rather_than_blocking_uploads(): void
    {
        config(['security.clamav_enabled' => true, 'security.clamav_binary' => '/this/binary/does/not/exist']);

        $file = UploadedFile::fake()->create('receipt.pdf', 10);

        $this->assertNull(VirusScan::problem($file));
    }
}
