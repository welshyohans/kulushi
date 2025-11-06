<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Credit Manager</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
        crossorigin="anonymous"
    >
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(130deg, #f5f7ff 0%, #eef1ff 45%, #dde4ff 100%);
        }

        .navbar {
            box-shadow: 0 12px 28px -18px rgba(63, 81, 181, 0.45);
        }

        .page-title {
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 20px 40px -25px rgba(33, 37, 41, 0.35);
        }

        .form-label {
            font-weight: 600;
        }

        .summary-card {
            background: linear-gradient(135deg, rgba(63, 81, 181, 0.06) 0%, rgba(0, 123, 255, 0.08) 100%);
            border-radius: 0.9rem;
            padding: 1.5rem;
        }

        .summary-card h2 {
            font-size: 2rem;
            margin-bottom: 0;
        }

        .empty-state {
            padding: 3.5rem 1.5rem;
            text-align: center;
            color: #6c757d;
        }

        .badge-pill {
            border-radius: 50rem;
        }

        #customerDetails {
            display: none;
        }

        .input-group-text {
            min-width: 3rem;
            justify-content: center;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container">
        <span class="navbar-brand fw-semibold text-primary">
            <i class="bi bi-credit-card-2-front-fill me-2"></i>
            Customer Credit Manager
        </span>
        <button class="btn btn-outline-primary" id="resetViewBtn">
            <i class="bi bi-arrow-counterclockwise me-1"></i>
            Reset
        </button>
    </div>
</nav>

<main class="container py-5">
    <div class="row justify-content-center g-4">
        <section class="col-lg-5">
            <div class="card">
                <div class="card-header bg-white border-0 pt-4 pb-3">
                    <h1 class="h4 page-title mb-1">Lookup customer</h1>
                    <p class="text-muted mb-0">Find a customer by ID or phone to review their credit limit.</p>
                </div>
                <div class="card-body pt-0">
                    <div class="alert d-none" id="feedbackAlert" role="alert"></div>
                    <form id="lookupForm" class="needs-validation" novalidate>
                        <div class="mb-3">
                            <label for="customerIdInput" class="form-label text-muted small text-uppercase">Customer ID</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-hash"></i>
                                </span>
                                <input
                                    type="number"
                                    min="1"
                                    class="form-control"
                                    id="customerIdInput"
                                    placeholder="e.g. 1024"
                                >
                            </div>
                            <div class="form-text">Optional, but preferred for precise lookup.</div>
                        </div>
                        <div class="mb-3">
                            <label for="phoneInput" class="form-label text-muted small text-uppercase">Phone number</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-telephone"></i>
                                </span>
                                <input
                                    type="tel"
                                    class="form-control"
                                    id="phoneInput"
                                    placeholder="e.g. 0911 000000"
                                >
                            </div>
                            <div class="form-text">Use only if customer ID is unknown.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" id="lookupBtn">
                            <span class="spinner-border spinner-border-sm d-none" id="lookupSpinner" role="status" aria-hidden="true"></span>
                            <span id="lookupBtnText">Fetch customer</span>
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <section class="col-lg-7">
            <div class="card h-100" id="customerCard">
                <div class="card-header bg-white border-0 pt-4 pb-2 d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="h5 page-title mb-1">Customer credit overview</h2>
                        <p class="text-muted mb-0">Review current credit and adjust permitted limits.</p>
                    </div>
                    <span class="badge bg-light text-dark badge-pill" id="customerStatusBadge">Awaiting lookup</span>
                </div>
                <div class="card-body">
                    <div class="empty-state" id="customerEmptyState">
                        <i class="bi bi-search-heart display-6 d-block mb-3 text-primary"></i>
                        <p class="mb-0">Search for a customer to load their credit details.</p>
                        <small class="text-muted">You can use either the customer ID or phone number.</small>
                    </div>

                    <div id="customerDetails">
                        <div class="summary-card mb-4">
                            <div class="row g-3">
                                <div class="col-md-4 border-end border-light-subtle">
                                    <small class="text-uppercase text-muted fw-semibold">Permitted credit</small>
                                    <h2 class="text-primary" id="summaryPermittedCredit">0</h2>
                                </div>
                                <div class="col-md-4 border-end border-light-subtle">
                                    <small class="text-uppercase text-muted fw-semibold">Total credit</small>
                                    <h2 class="text-dark" id="summaryTotalCredit">—</h2>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-uppercase text-muted fw-semibold">Total unpaid</small>
                                    <h2 class="text-danger" id="summaryTotalUnpaid">—</h2>
                                </div>
                            </div>
                        </div>

                        <dl class="row mb-4">
                            <dt class="col-sm-4 text-muted text-uppercase small">Customer</dt>
                            <dd class="col-sm-8 fw-semibold" id="detailCustomerName">—</dd>

                            <dt class="col-sm-4 text-muted text-uppercase small">Shop</dt>
                            <dd class="col-sm-8" id="detailShopName">—</dd>

                            <dt class="col-sm-4 text-muted text-uppercase small">Phone</dt>
                            <dd class="col-sm-8" id="detailPhone">—</dd>
                        </dl>

                        <form id="updateCreditForm" class="needs-validation" novalidate>
                            <div class="row g-3 align-items-end">
                                <div class="col-md-6">
                                    <label for="permittedCreditInput" class="form-label">New permitted credit</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="bi bi-cash-stack"></i>
                                        </span>
                                        <input
                                            type="number"
                                            min="0"
                                            class="form-control"
                                            id="permittedCreditInput"
                                            required
                                        >
                                        <div class="invalid-feedback">
                                            Enter a non-negative integer value.
                                        </div>
                                    </div>
                                    <div class="form-text">Current limit:
                                        <span class="fw-semibold text-primary" id="currentCreditValue">0</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <button type="submit" class="btn btn-success w-100" id="updateCreditBtn" disabled>
                                        <span class="spinner-border spinner-border-sm d-none" id="updateSpinner" role="status" aria-hidden="true"></span>
                                        <span id="updateBtnText">Update permitted credit</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                        <hr class="my-4">
                        <div class="small text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            Updating the permitted credit affects maximum allowable credit purchases for the customer. Ensure any outstanding balance considerations are reviewed before proceeding.
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</main>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
        crossorigin="anonymous"></script>
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
    crossorigin="anonymous"
></script>
<script>
    (function ($) {
        'use strict';

        const state = {
            customer: null
        };

        function showAlert(type, message) {
            const alertEl = $('#feedbackAlert');
            alertEl.removeClass('d-none alert-success alert-danger alert-warning alert-info')
                .addClass(`alert alert-${type}`)
                .text(message);

            setTimeout(() => alertEl.addClass('d-none'), 4000);
        }

        function setLookupLoading(isLoading) {
            $('#lookupBtn').prop('disabled', isLoading);
            $('#lookupSpinner').toggleClass('d-none', !isLoading);
            $('#lookupBtnText').text(isLoading ? 'Fetching…' : 'Fetch customer');
        }

        function setUpdateLoading(isLoading) {
            $('#updateCreditBtn').prop('disabled', isLoading);
            $('#updateSpinner').toggleClass('d-none', !isLoading);
            $('#updateBtnText').text(isLoading ? 'Updating…' : 'Update permitted credit');
        }

        function formatCurrency(value) {
            if (value === null || value === undefined) {
                return '—';
            }
            const number = Number(value);
            if (Number.isNaN(number)) {
                return String(value);
            }
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: 'ETB',
                minimumFractionDigits: 2
            }).format(number);
        }

        function populateCustomerDetails(customer) {
            state.customer = customer;

            $('#customerEmptyState').hide();
            $('#customerDetails').show();
            $('#customerStatusBadge')
                .removeClass()
                .addClass('badge bg-success-subtle text-success badge-pill')
                .text(`ID #${customer.id}`);

            $('#summaryPermittedCredit').text(customer.permittedCredit ?? 0);
            $('#summaryTotalCredit').text(formatCurrency(customer.totalCredit));
            $('#summaryTotalUnpaid').text(formatCurrency(customer.totalUnpaid));

            $('#detailCustomerName').text(customer.name || '—');
            $('#detailShopName').text(customer.shopName || '—');
            $('#detailPhone').text(customer.phone || '—');

            $('#currentCreditValue').text(customer.permittedCredit ?? 0);
            $('#permittedCreditInput').val(customer.permittedCredit ?? 0);
            $('#updateCreditBtn').prop('disabled', false);
        }

        function resetCustomerView() {
            state.customer = null;
            $('#customerDetails').hide();
            $('#customerEmptyState').show();
            $('#customerStatusBadge')
                .removeClass()
                .addClass('badge bg-light text-dark badge-pill')
                .text('Awaiting lookup');
            $('#updateCreditBtn').prop('disabled', true);
            $('#lookupForm')[0].reset();
            $('#updateCreditForm')[0].reset();
            $('#currentCreditValue').text('0');
            $('#summaryPermittedCredit').text('0');
            $('#summaryTotalCredit').text('—');
            $('#summaryTotalUnpaid').text('—');
            $('#feedbackAlert').addClass('d-none');
        }

        function readLookupInputs() {
            const idValue = $('#customerIdInput').val();
            const phoneValue = $('#phoneInput').val();

            const payload = {};

            if (idValue !== null && idValue !== '') {
                const parsedId = Number(idValue);
                if (Number.isInteger(parsedId) && parsedId > 0) {
                    payload.customerId = parsedId;
                } else {
                    throw new Error('Customer ID must be a positive integer.');
                }
            }

            if (phoneValue !== null && phoneValue.trim() !== '') {
                payload.phone = phoneValue.trim();
            }

            if (!('customerId' in payload) && !('phone' in payload)) {
                throw new Error('Provide either a customer ID or phone number.');
            }

            return payload;
        }

        function registerEventHandlers() {
            $('#lookupForm').on('submit', (event) => {
                event.preventDefault();

                let params;
                try {
                    params = readLookupInputs();
                } catch (error) {
                    showAlert('warning', error.message);
                    return;
                }

                setLookupLoading(true);
                $.ajax({
                    url: '../api/updateCredit.php',
                    method: 'GET',
                    data: params,
                    dataType: 'json'
                }).done((response) => {
                    if (response.success && response.customer) {
                        populateCustomerDetails(response.customer);
                        showAlert('success', 'Customer credit information loaded.');
                    } else {
                        resetCustomerView();
                        showAlert('warning', response.message || 'Customer not found.');
                    }
                }).fail((jqXHR) => {
                    resetCustomerView();
                    const message = jqXHR.responseJSON?.message || 'Unable to load customer information.';
                    showAlert('danger', message);
                }).always(() => {
                    setLookupLoading(false);
                });
            });

            $('#updateCreditForm').on('submit', (event) => {
                event.preventDefault();
                const form = event.target;

                if (!state.customer) {
                    showAlert('warning', 'Load a customer before updating credit.');
                    return;
                }

                if (!form.checkValidity()) {
                    form.classList.add('was-validated');
                    return;
                }
                form.classList.remove('was-validated');

                const newCreditValue = Number($('#permittedCreditInput').val());
                if (!Number.isInteger(newCreditValue) || newCreditValue < 0) {
                    showAlert('warning', 'Permitted credit must be a non-negative integer.');
                    return;
                }

                if (newCreditValue === state.customer.permittedCredit) {
                    showAlert('info', 'Permitted credit is unchanged.');
                    return;
                }

                setUpdateLoading(true);
                $.ajax({
                    url: '../api/updateCredit.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        customerId: state.customer.id,
                        permittedCredit: newCreditValue
                    }),
                    dataType: 'json'
                }).done((response) => {
                    if (response.success) {
                        showAlert('success', response.message || 'Permitted credit updated successfully.');
                        state.customer.permittedCredit = newCreditValue;
                        populateCustomerDetails(state.customer);
                    } else {
                        showAlert('warning', response.message || 'No changes were applied.');
                    }
                }).fail((jqXHR) => {
                    const message = jqXHR.responseJSON?.message || 'Server error while updating credit.';
                    showAlert('danger', message);
                }).always(() => {
                    setUpdateLoading(false);
                });
            });

            $('#resetViewBtn').on('click', () => {
                resetCustomerView();
            });
        }

        $(document).ready(() => {
            registerEventHandlers();
        });
    })(jQuery);
</script>
</body>
</html>