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
                            $words.Add(@{ text = $word.Text; x = $word.BoundingRect.X; y = $center; width = $word.BoundingRect.Width; height = $word.BoundingRect.Height })
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
            $regionDefinitions = @(
                @{ name = 'identification'; x = 0.06; y = 0.28; width = 0.91; height = 0.065 },
                @{ name = 'issued'; x = 0.06; y = 0.68; width = 0.91; height = 0.065 },
                @{ name = 'validity'; x = 0.06; y = 0.53; width = 0.91; height = 0.075 },
                @{ name = 'header'; x = 0.06; y = 0.10; width = 0.91; height = 0.075 }
            )
            $modelLabel = $words | Where-Object { $_.text -match '^MODELO[:.]?$' -and $_.x -lt $decoder.PixelWidth * 0.30 -and $_.y -gt $decoder.PixelHeight * 0.35 -and $_.y -lt $decoder.PixelHeight * 0.60 } | Select-Object -First 1
            if ($null -ne $modelLabel) {
                $brandLabel = $words | Where-Object { $_.text -match '^MARCA[:.]?$' -and $_.x -lt $decoder.PixelWidth * 0.30 -and $_.y -lt $modelLabel.y -and ($modelLabel.y - $_.y) -lt $modelLabel.height * 3 } | Select-Object -Last 1
                $step = $modelLabel.height * 1.65
                if ($null -ne $brandLabel) { $step = $modelLabel.y - $brandLabel.y }
                $vehicleFields = @(@{ name = 'vehicle_brand'; offset = -1 }, @{ name = 'vehicle_model'; offset = 0 }, @{ name = 'vehicle_series'; offset = 1 })
                if ($text -match '\bBERA\b') { $vehicleFields += @{ name = 'vehicle_years'; offset = 2 } }
                foreach ($field in $vehicleFields) {
                    $regionDefinitions += @{ name = $field.name; x = 0.267; y = ($modelLabel.y + $field.offset * $step - $step * 0.43) / $decoder.PixelHeight; width = 0.255; height = $step * 0.86 / $decoder.PixelHeight }
                }
            }
            $serialLabel = $words | Where-Object { $_.text -match '^PATR' -and $_.y -gt $decoder.PixelHeight * 0.25 -and $_.y -lt $decoder.PixelHeight * 0.43 } | Select-Object -First 1
            if ($null -ne $serialLabel) {
                $serialX = ($serialLabel.x + $serialLabel.width) / $decoder.PixelWidth
                $regionDefinitions += @{ name = 'serial'; x = $serialX; y = ($serialLabel.y - $serialLabel.height * 0.85) / $decoder.PixelHeight; width = [Math]::Min(0.30, 0.97 - $serialX); height = $serialLabel.height * 1.7 / $decoder.PixelHeight }
            } else {
                $serialWord = $words | Where-Object { $_.text.Trim(':;,.') -match '^[A-Z0-9]{8}[0-9XO][A-Z0-9]{2}[0-9OIL]{6}$' -and $_.x -gt $decoder.PixelWidth * 0.30 -and $_.y -gt $decoder.PixelHeight * 0.25 -and $_.y -lt $decoder.PixelHeight * 0.43 } | Select-Object -First 1
                if ($null -ne $serialWord) {
                    $regionDefinitions += @{ name = 'serial'; x = [Math]::Max(0.0, ($serialWord.x - 15) / $decoder.PixelWidth); y = ($serialWord.y - $serialWord.height * 0.85) / $decoder.PixelHeight; width = ($serialWord.width + 30) / $decoder.PixelWidth; height = $serialWord.height * 1.7 / $decoder.PixelHeight }
                }
            }
            foreach ($region in $regionDefinitions) {
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
