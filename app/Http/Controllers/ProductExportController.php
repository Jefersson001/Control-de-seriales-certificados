<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportProductsRequest;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductExportController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(ExportProductsRequest $request): BinaryFileResponse
    {
        $search = trim((string) ($request->validated('search') ?? ''));
        $temporaryFile = tempnam(sys_get_temp_dir(), 'productos-');

        abort_if($temporaryFile === false, 500, 'No se pudo preparar el archivo de exportación.');

        $xlsxPath = $temporaryFile.'.xlsx';
        rename($temporaryFile, $xlsxPath);

        $writer = new Writer;
        $writer->openToFile($xlsxPath);
        $writer->addRow(Row::fromValues(['Descripción', '1ero', '2do', 'NIV', 'Año']));

        foreach (
            Product::query()
                ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('first_value', 'like', "%{$search}%")
                        ->orWhere('second_value', 'like', "%{$search}%")
                        ->orWhere('niv', 'like', "%{$search}%")
                        ->when(ctype_digit($search), fn (Builder $query) => $query->orWhere('year', (int) $search));
                }))
                ->oldest('id')
                ->cursor() as $product
        ) {
            $writer->addRow(Row::fromValues([
                $product->name,
                $product->first_value,
                $product->second_value,
                $product->niv,
                $product->year,
            ]));
        }

        $writer->close();

        return response()
            ->download($xlsxPath, 'productos-'.now()->format('Y-m-d').'.xlsx')
            ->deleteFileAfterSend();
    }
}
