<?php
/**
 * Nextools - Config Form Styles
 *
 * Estilos de compatibilidade e tema do formulário de configuração do Nextools
 * no GLPI 10 (layout, abas, cards de módulos).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
?>
<style>
   /* Escopado a #nextool-config-form (container raiz do formulário de config). */
   #nextool-config-form .btn-outline-licensing {
      background-color: #b3541e;
      border-color: #b3541e;
      color: #ffffff;
   }

   #nextool-config-form .btn-outline-licensing:hover,
   #nextool-config-form .btn-outline-licensing:focus {
      background-color: #e58d50;
      border-color: #e58d50;
      color: #ffffff;
   }

   #nextool-config-form .text-licensing {
      color: #b3541e !important;
   }

   #nextool-config-form .text-licensing-hero {
      color: #FACC15 !important;
   }

   #nextool-config-form .border-licensing {
      border-color: #b3541e !important;
   }

   #nextool-config-form .badge-licensing {
      background-color: #b3541e;
      color: #ffffff;
   }

   #nextool-config-form .badge-dev {
      background-color: #0ea5e9;
      color: #ffffff;
   }

   #nextool-config-form .btn-hero-validate {
      background-color: #FACC15;
      border-color: #FACC15;
      color: #111827;
   }

   #nextool-config-form .btn-hero-validate:hover,
   #nextool-config-form .btn-hero-validate:focus {
      background-color: #FEF9C3;
      border-color: #FEF9C3;
      color: #111827;
   }

   #nextool-config-form .nextool-policy-actions {
      max-width: 480px;
   }

   #nextool-config-form .nextool-tab-card {
      margin-top: 1rem;
      color: #1f2937 !important;
   }

   #nextool-config-form .nextool-tab-card .card-body {
      color: #1f2937 !important;
   }

   #nextool-config-form .nextool-tab-card .text-muted {
      color: #6b7280 !important;
   }

   #nextool-config-form .nextool-tab-card .form-control,
   #nextool-config-form .nextool-tab-card .form-select,
   #nextool-config-form .nextool-tab-card input,
   #nextool-config-form .nextool-tab-card textarea {
      color: #1f2937 !important;
   }

   #nextool-config-form .nextool-tab-card .form-control[readonly] {
      -webkit-text-fill-color: #1f2937;
      opacity: 1;
   }

   #nextool-config-form .nextool-hero-actions {
      text-align: left;
      margin-top: 0.5rem;
   }

   @media (min-width: 992px) {
      #nextool-config-form .nextool-hero-actions {
         text-align: right;
      }
   }

   /* === Module Filter Bar === */
   #nextool-config-form #nextool-module-filter-bar {
      border-bottom: 1px solid #e5e7eb;
      padding-bottom: 0.75rem;
   }
   #nextool-config-form .nextool-filter-chip {
      font-size: 0.8rem;
      transition: all 0.2s ease;
      cursor: pointer;
      color: #fff !important;
      box-shadow: 0 1px 3px rgba(0,0,0,0.2);
   }
   #nextool-config-form .nextool-filter-chip:hover {
      box-shadow: 0 3px 8px rgba(0,0,0,0.3);
      filter: brightness(1.1);
   }
   #nextool-config-form .nextool-filter-chip .badge {
      font-size: 0.7rem;
      min-width: 1.2rem;
      background: rgba(255,255,255,0.25) !important;
      color: #fff !important;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-success {
      background-color: #198754;
      border-color: #198754;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-warning {
      background-color: #ffc107;
      border-color: #ffc107;
      color: #000 !important;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-warning .badge {
      background: rgba(0,0,0,0.15) !important;
      color: #000 !important;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-secondary {
      background-color: #6c757d;
      border-color: #6c757d;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-info {
      background-color: #0dcaf0;
      border-color: #0dcaf0;
      color: #000 !important;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-info .badge {
      background: rgba(0,0,0,0.15) !important;
      color: #000 !important;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-teal,
   #nextool-config-form .btn-outline-teal {
      background-color: #0d9488;
      border-color: #0d9488;
      color: #fff !important;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-licensing {
      background-color: #b3541e;
      border-color: #b3541e;
   }
   #nextool-config-form .nextool-filter-chip.active {
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px currentColor;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-success.active {
      background-color: #146c43;
      border-color: #146c43;
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px #198754;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-warning.active {
      background-color: #e0a800;
      border-color: #e0a800;
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px #ffc107;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-secondary.active {
      background-color: #565e64;
      border-color: #565e64;
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px #6c757d;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-info.active {
      background-color: #0ab3d8;
      border-color: #0ab3d8;
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px #0dcaf0;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-teal.active,
   #nextool-config-form .btn-outline-teal.active {
      background-color: #0a7a70;
      border-color: #0a7a70;
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px #0d9488;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-licensing.active {
      background-color: #934518;
      border-color: #934518;
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px #b3541e;
   }
   #nextool-config-form .bg-teal {
      background-color: #0d9488 !important;
   }
   #nextool-config-form .bg-licensing {
      background-color: #b3541e !important;
   }
   #nextool-config-form #nextool-module-no-results {
      font-size: 0.95rem;
   }
   #nextool-config-form #nextool-module-search:focus {
      box-shadow: none;
      border-color: #ced4da;
   }

   /* GLPI 10 legado usa #page .small { width: 1% }, causando textos verticais no hero */
   #page #nextool-config-form .small,
   .qtip #nextool-config-form .small,
   .modal .modal-body #nextool-config-form .small {
      width: auto;
   }
</style>
