param(
    [Parameter(Mandatory = $true)][string]$Path,
    [int]$MaxPages = 10,
    [string]$LanguageTag = 'es-ES'
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
Add-Type -AssemblyName System.Runtime.WindowsRuntime
[Windows.Storage.StorageFile, Windows.Storage, ContentType = WindowsRuntime] | Out-Null
[Windows.Data.Pdf.PdfDocument, Windows.Data.Pdf, ContentType = WindowsRuntime] | Out-Null
[Windows.Storage.Streams.InMemoryRandomAccessStream, Windows.Storage.Streams, ContentType = WindowsRuntime] | Out-Null
[Windows.Graphics.Imaging.BitmapDecoder, Windows.Graphics.Imaging, ContentType = WindowsRuntime] | Out-Null
[Windows.Media.Ocr.OcrEngine, Windows.Foundation, ContentType = WindowsRuntime] | Out-Null
[Windows.Globalization.Language, Windows.Globalization, ContentType = WindowsRuntime] | Out-Null
Add-Type -TypeDefinition @'
public static class ComplianceOcrThreshold {
    public static byte[] Apply(byte[] pixels, int threshold) {
        byte[] result = (byte[])pixels.Clone();
        for (int i = 0; i < result.Length; i += 4) {
            int grey = (pixels[i] * 114 + pixels[i + 1] * 587 + pixels[i + 2] * 299) / 1000;
            byte value = grey < threshold ? (byte)0 : (byte)255;
            result[i] = result[i + 1] = result[i + 2] = value;
            result[i + 3] = 255;
        }
        return result;
    }
}
'@

$operationMethod = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and $_.IsGenericMethodDefinition -and $_.GetGenericArguments().Count -eq 1 -and $_.GetParameters().Count -eq 1
} | Select-Object -First 1
$actionMethod = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and -not $_.IsGenericMethodDefinition -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncAction'
} | Select-Object -First 1

function Wait-Operation($Operation, [type]$ResultType) {
    $task = $operationMethod.MakeGenericMethod($ResultType).Invoke($null, @($Operation))
    return $task.GetAwaiter().GetResult()
}

try {
    $language = [Windows.Globalization.Language]::new($LanguageTag)
    $engine = [Windows.Media.Ocr.OcrEngine]::TryCreateFromLanguage($language)
    if ($null -eq $engine) { $engine = [Windows.Media.Ocr.OcrEngine]::TryCreateFromUserProfileLanguages() }
    if ($null -eq $engine) { throw 'No hay un idioma de reconocimiento de texto instalado en Windows.' }

    $file = Wait-Operation ([Windows.Storage.StorageFile]::GetFileFromPathAsync([IO.Path]::GetFullPath($Path))) ([Windows.Storage.StorageFile])
    $document = Wait-Operation ([Windows.Data.Pdf.PdfDocument]::LoadFromFileAsync($file)) ([Windows.Data.Pdf.PdfDocument])
    if ($document.PageCount -gt $MaxPages) { throw "La constancia no puede superar las $MaxPages paginas." }
    $pages = @()

    for ($index = 0; $index -lt $document.PageCount; $index++) {
        $page = $document.GetPage($index)
        $stream = [Windows.Storage.Streams.InMemoryRandomAccessStream]::new()
        $bitmap = $null
        try {
            $limit = [Math]::Min(2500, [Windows.Media.Ocr.OcrEngine]::MaxImageDimension)
            $scale = [Math]::Min($limit / $page.Size.Width, 3500 / $page.Size.Height)
            $options = [Windows.Data.Pdf.PdfPageRenderOptions]::new()
            $options.DestinationWidth = [uint32][Math]::Floor($page.Size.Width * $scale)
            $options.DestinationHeight = [uint32][Math]::Floor($page.Size.Height * $scale)
            $renderTask = $actionMethod.Invoke($null, @($page.RenderToStreamAsync($stream, $options)))
            $renderTask.GetAwaiter().GetResult() | Out-Null
            $stream.Seek(0)
            $decoder = Wait-Operation ([Windows.Graphics.Imaging.BitmapDecoder]::CreateAsync($stream)) ([Windows.Graphics.Imaging.BitmapDecoder])
            $words = [Collections.Generic.List[object]]::new()
            $bandHeight = [int][Math]::Ceiling($decoder.PixelHeight / 3)
            for ($band = 0; $band -lt 3; $band++) {
                $coreStart = $band * $bandHeight
                $coreEnd = [Math]::Min(($band + 1) * $bandHeight, $decoder.PixelHeight)
                $start = [Math]::Max(0, $coreStart - 100)
                $end = [Math]::Min($decoder.PixelHeight, $coreEnd + 100)
                $bounds = [Windows.Graphics.Imaging.BitmapBounds]::new()
                $bounds.X = 0
                $bounds.Y = [uint32]$start
                $bounds.Width = $decoder.PixelWidth
                $bounds.Height = [uint32]($end - $start)
                $transform = [Windows.Graphics.Imaging.BitmapTransform]::new()
                $transform.Bounds = $bounds
                $bitmap = Wait-Operation ($decoder.GetSoftwareBitmapAsync([Windows.Graphics.Imaging.BitmapPixelFormat]::Bgra8, [Windows.Graphics.Imaging.BitmapAlphaMode]::Premultiplied, $transform, [Windows.Graphics.Imaging.ExifOrientationMode]::IgnoreExifOrientation, [Windows.Graphics.Imaging.ColorManagementMode]::DoNotColorManage)) ([Windows.Graphics.Imaging.SoftwareBitmap])
                $result = Wait-Operation ($engine.RecognizeAsync($bitmap)) ([Windows.Media.Ocr.OcrResult])
                foreach ($line in $result.Lines) {
                    foreach ($word in $line.Words) {
                        $center = $word.BoundingRect.Y + $start + $word.BoundingRect.Height / 2
                        if ($center -ge $coreStart -and $center -lt $coreEnd) {
                            $words.Add(@{ text = $word.Text; x = $word.BoundingRect.X; y = $center; height = $word.BoundingRect.Height })
                        }
                    }
                }
                $bitmap.Dispose()
                $bitmap = $null
            }
            $rows = [Collections.Generic.List[object]]::new()
            foreach ($word in ($words | Sort-Object { $_.y })) {
                $row = $rows | Where-Object { [Math]::Abs($_.y - $word.y) -le [Math]::Max(9, $word.height * 0.5) } | Select-Object -Last 1
                if ($null -eq $row) {
                    $row = @{ y = $word.y; words = [Collections.Generic.List[object]]::new() }
                    $rows.Add($row)
                }
                $row.words.Add($word)
            }
            $text = ($rows | ForEach-Object { ($_.words | Sort-Object { $_.x } | ForEach-Object { $_.text }) -join ' ' }) -join "`n"
            $regions = @{}
            foreach ($region in @(
                @{ name = 'identification'; x = 0.06; y = 0.28; width = 0.91; height = 0.065 },
                @{ name = 'issued'; x = 0.06; y = 0.68; width = 0.91; height = 0.065 },
                @{ name = 'validity'; x = 0.06; y = 0.53; width = 0.91; height = 0.075 }
            )) {
                $bounds = [Windows.Graphics.Imaging.BitmapBounds]::new()
                $bounds.X = [uint32]($decoder.PixelWidth * $region.x)
                $bounds.Y = [uint32]($decoder.PixelHeight * $region.y)
                $bounds.Width = [uint32]($decoder.PixelWidth * $region.width)
                $bounds.Height = [uint32]($decoder.PixelHeight * $region.height)
                $transform = [Windows.Graphics.Imaging.BitmapTransform]::new()
                $transform.Bounds = $bounds
                $bitmap = Wait-Operation ($decoder.GetSoftwareBitmapAsync([Windows.Graphics.Imaging.BitmapPixelFormat]::Bgra8, [Windows.Graphics.Imaging.BitmapAlphaMode]::Premultiplied, $transform, [Windows.Graphics.Imaging.ExifOrientationMode]::IgnoreExifOrientation, [Windows.Graphics.Imaging.ColorManagementMode]::DoNotColorManage)) ([Windows.Graphics.Imaging.SoftwareBitmap])
                $result = Wait-Operation ($engine.RecognizeAsync($bitmap)) ([Windows.Media.Ocr.OcrResult])
                $regions[$region.name] = ($result.Lines | ForEach-Object { $_.Text }) -join "`n"
                & {
                    $buffer = [Windows.Storage.Streams.Buffer]::new([uint32]($bitmap.PixelWidth * $bitmap.PixelHeight * 4))
                    $bitmap.CopyToBuffer($buffer)
                    $reader = [Windows.Storage.Streams.DataReader]::FromBuffer($buffer)
                    $pixels = [byte[]]::new($buffer.Length)
                    $reader.ReadBytes($pixels)
                    $reader.Dispose()
                    foreach ($threshold in @(70, 120, 175)) {
                        $processed = [Windows.Graphics.Imaging.SoftwareBitmap]::new([Windows.Graphics.Imaging.BitmapPixelFormat]::Bgra8, $bitmap.PixelWidth, $bitmap.PixelHeight, [Windows.Graphics.Imaging.BitmapAlphaMode]::Premultiplied)
                        try {
                            $processed.CopyFromBuffer([System.Runtime.InteropServices.WindowsRuntime.WindowsRuntimeBufferExtensions]::AsBuffer([ComplianceOcrThreshold]::Apply($pixels, $threshold)))
                            $result = Wait-Operation ($engine.RecognizeAsync($processed)) ([Windows.Media.Ocr.OcrResult])
                            $regions[$region.name] += "`n" + (($result.Lines | ForEach-Object { $_.Text }) -join "`n")
                        } finally { $processed.Dispose() }
                    }
                }
                $bitmap.Dispose()
                $bitmap = $null
            }
            $pages += @{ page = $index + 1; text = $text; regions = $regions }
        } finally {
            if ($null -ne $bitmap) { $bitmap.Dispose() }
            $stream.Dispose()
            $page.Dispose()
        }
    }

    @{ pages = $pages } | ConvertTo-Json -Depth 5 -Compress
} catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
