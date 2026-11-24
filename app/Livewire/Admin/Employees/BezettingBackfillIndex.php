<?php

namespace App\Livewire\Admin\Employees;

use App\Exceptions\ImportAbortedException;
use App\Models\BezettingBackfillRun;
use App\Services\Bezetting\BezettingBackfillService;
use App\Support\HrisPermission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

/**
 * Rekonstruksi Riwayat Karir dari Bezetting (DOMAIN.md §5.5): unggah berkas bulanan dan daftar proses. Aturan ada pada
 * BezettingBackfillService; layar ini hanya mengunggah dan menampilkan. Berkas dihapus segera setelah dibaca.
 */
class BezettingBackfillIndex extends Component
{
    use WithFileUploads;
    use WithPagination;

    /** @var array<int, TemporaryUploadedFile> */
    public array $files = [];

    public int $perPage = 10;

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        $this->authorizeImport();
    }

    public function stage(BezettingBackfillService $service)
    {
        $this->authorizeImport();
        $max = (int) config('hris.bezetting.max_files', 24);
        $kb = (int) config('hris.bezetting.max_file_kb', 10240);

        $this->validate(
            ['files' => "required|array|min:1|max:{$max}", 'files.*' => "file|mimes:xls,xlsx|max:{$kb}"],
            [
                'files.required' => 'Pilih minimal satu berkas Bezetting.',
                'files.min' => 'Pilih minimal satu berkas Bezetting.',
                'files.max' => "Paling banyak {$max} berkas dalam satu proses.",
                'files.*.mimes' => 'Setiap berkas harus berformat Excel (.xls atau .xlsx).',
                'files.*.max' => 'Setiap berkas paling besar '.intdiv($kb, 1024).' MB.',
            ],
        );

        $payload = array_map(fn ($file) => ['path' => $file->getRealPath(), 'name' => $file->getClientOriginalName()], $this->files);

        try {
            $run = $service->stage($payload, auth()->user());
        } catch (ImportAbortedException $e) {
            $this->files = [];
            $this->addError('files', $e->getMessage());

            return null;
        } catch (Throwable $e) {
            Log::error('Bezetting staging failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $this->files = [];
            $this->addError('files', 'Berkas tidak dapat diproses. Periksa format berkas lalu coba lagi.');

            return null;
        }

        $this->files = [];
        session()->flash('success', 'Berkas terbaca dan sudah dihapus dari server. Tinjau nama unit dan jabatan sebelum diajukan.');

        return $this->redirectRoute('admin.employees.bezetting.show', $run, navigate: true);
    }

    public function render(): View
    {
        $this->authorizeImport();

        return view('livewire.admin.employees.bezetting-backfill-index', [
            'runs' => BezettingBackfillRun::query()->latest('id')
                ->paginate(in_array($this->perPage, array_map('intval', config('hris.pagination.options')), true) ? $this->perPage : 10),
            'undoWindowDays' => (int) config('hris.import.undo_window_days', 7),
            'approvalTtlDays' => (int) config('hris.import.approval_ttl_days', 7),
            'maxFiles' => (int) config('hris.bezetting.max_files', 24),
        ]);
    }

    private function authorizeImport(): void
    {
        abort_unless(Gate::allows(HrisPermission::KARYAWAN_IMPORT), 403, 'Unauthorized.');
    }
}
