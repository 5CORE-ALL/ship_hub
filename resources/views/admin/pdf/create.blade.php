@extends('admin.layouts.admin_master')
@section('title', 'Multiple PDF Upload')
@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Upload Multiple PDFs</h4>
                </div>
                <div class="card-body">
                    @if (session('success'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif
                    @if (session('error'))
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            {{ session('error') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif
                    <div class="mb-3">
                        <label for="upload_date" class="form-label">Upload Date</label>
                        <input type="date" class="form-control @error('upload_date') is-invalid @enderror" id="upload_date" name="upload_date" value="{{ old('upload_date', now()->format('Y-m-d')) }}" required>
                        @error('upload_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div id="my-dropzone" class="dropzone mb-3"></div>
                    
                    {{-- Progress and Loader Section --}}
                    <div id="upload-progress" style="display: none;" class="mb-3">
                        <div class="d-flex justify-content-center align-items-center">
                            <div class="spinner-border text-primary me-2" role="status">
                                <span class="visually-hidden">Uploading...</span>
                            </div>
                            <div>
                                <div id="progress-text" class="text-muted">Processing 0 of <span id="total-count">0</span> PDFs...</div>
                                <div class="progress mt-2" style="height: 6px;">
                                    <div id="progress-bar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
                                </div>
                                <div id="progress-errors" class="text-danger small mt-1" style="display: none;"></div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="process-upload" class="btn btn-primary" style="display: none;">Upload PDFs</button>
                    <a href="{{ route('pdfs.index') }}" class="btn btn-secondary">Back to List</a>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Dropzone CSS and JS via CDN --}}
<link rel="stylesheet" href="https://unpkg.com/dropzone@5/dist/min/dropzone.min.css" type="text/css" />
<script src="https://unpkg.com/dropzone@5/dist/min/dropzone.min.js"></script>

<script>
    // Configure Dropzone
    Dropzone.options.myDropzone = {
        url: "{{ route('pdfs.upload') }}",
        paramName: "pdf_files", // Single file per request
        maxFilesize: 10, // MB
        acceptedFiles: ".pdf",
        uploadMultiple: false, // Changed to false: Each file uploads separately (parallel via parallelUploads)
        autoProcessQueue: false, // Wait for button click to upload
        parallelUploads: 10, // Upload up to 10 files in parallel (all at once feel)
        addRemoveLinks: true,
        dictDefaultMessage: "Drop PDF files here or click to browse (Multiple files allowed)",
        dictRemoveFile: "Remove file",
        init: function() {
            var myDropzone = this;
            var totalFiles = 0;
            var processedFiles = 0;
            var successfulUploads = 0;
            var errorMessages = [];

            // Show upload button when files are added
            myDropzone.on("addedfile", function() {
                document.getElementById('process-upload').style.display = 'inline-block';
            });

            // Hide button if no files
            myDropzone.on("removedfile", function() {
                if (myDropzone.files.length === 0) {
                    document.getElementById('process-upload').style.display = 'none';
                }
            });

            // Handle upload button click
            document.getElementById('process-upload').addEventListener("click", function() {
                // Validate upload_date
                var uploadDate = document.getElementById('upload_date').value;
                if (!uploadDate) {
                    alert('Please select an upload date.');
                    return;
                }
                // Initialize counters
                totalFiles = myDropzone.files.length;
                processedFiles = 0;
                successfulUploads = 0;
                errorMessages = [];
                document.getElementById('total-count').textContent = totalFiles;
                document.getElementById('progress-text').textContent = `Processing 0 of ${totalFiles} PDFs...`;
                document.getElementById('progress-bar').style.width = '0%';
                document.getElementById('progress-errors').style.display = 'none';
                document.getElementById('progress-errors').innerHTML = '';
                document.getElementById('upload-progress').style.display = 'block';
                document.getElementById('process-upload').disabled = true; // Disable button during upload
                // Process the queue (files upload in parallel)
                myDropzone.processQueue();
            });

            // Per-file upload progress (real-time based on controller responses)
            myDropzone.on("uploadprogress", function(file, progress, bytesSent) {
                // Optional: Show per-file progress if needed
                console.log(`${file.name}: ${Math.round(progress)}%`);
            });

            // Per-file success (controller returns JSON {success: true, message: '...'})
            myDropzone.on("success", function(file, response) {
                response = typeof response === 'string' ? JSON.parse(response) : response;
                processedFiles++;
                successfulUploads++;
                this.emit("uploadprogress", file, 100); // Mark as complete
                updateProgress();
                if (response.success) {
                    // Optional: Show per-file success message
                    console.log(`${file.name} uploaded successfully: ${response.message}`);
                }
            });

            // Per-file error (controller returns JSON {success: false, error: '...'})
            myDropzone.on("error", function(file, response) {
                response = typeof response === 'string' ? JSON.parse(response) : response;
                processedFiles++;
                var errorMsg = response.error || 'Unknown error';
                errorMessages.push(`${file.name}: ${errorMsg}`);
                updateProgress();
                // Show errors if any
                if (errorMessages.length > 0) {
                    document.getElementById('progress-errors').innerHTML = errorMessages.join('<br>');
                    document.getElementById('progress-errors').style.display = 'block';
                }
            });

            // Helper function to update UI
            function updateProgress() {
                var percentage = Math.round((processedFiles / totalFiles) * 100);
                document.getElementById('progress-text').textContent = `Processed ${processedFiles} of ${totalFiles} PDFs...`;
                document.getElementById('progress-bar').style.width = percentage + '%';
                document.getElementById('progress-bar').setAttribute('aria-valuenow', percentage);
                // If all done
                if (processedFiles === totalFiles) {
                    setTimeout(function() {
                        document.getElementById('upload-progress').style.display = 'none';
                        document.getElementById('process-upload').disabled = false;
                        if (errorMessages.length > 0) {
                            alert(`Upload completed with errors. Successful: ${successfulUploads}/${totalFiles}. Check errors above.`);
                        } else {
                            alert('All PDFs uploaded successfully!');
                            myDropzone.removeAllFiles();
                            document.getElementById('process-upload').style.display = 'none';
                            // Optional: Redirect
                            // window.location.href = "{{ route('pdfs.index') }}";
                        }
                    }, 1000); // Delay for visual effect
                }
            }

            // Add CSRF and upload_date to EACH file's form data (since separate requests)
            myDropzone.on("sending", function(file, xhr, formData) {
                formData.append('_token', '{{ csrf_token() }}');
                formData.append('upload_date', document.getElementById('upload_date').value);
            });
        }
    };
</script>
@endsection