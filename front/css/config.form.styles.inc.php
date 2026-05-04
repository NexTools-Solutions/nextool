<?php
declare(strict_types=1);
/**
 * Plugin NexTool - Estilos do formulário de configuração
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
?>
<style>
   /* Escopado a #nextool-config-form para não afetar o resto do GLPI e resistir a CSS genérico (padrão container raiz + seletores escopados). */

   /* Mitiga entity.css: .btn-outline-primary { border: none } que remove borda dos botões toggle (btn-check) */
   #nextool-config-form .btn-outline-primary:not(.nextool-filter-chip) {
      border: 1px solid currentColor !important;
   }

   #nextool-config-form .btn-outline-licensing {
      background-color: #7c3aed;
      border-color: #7c3aed;
      color: #ffffff;
   }

   #nextool-config-form .btn-outline-licensing:hover,
   #nextool-config-form .btn-outline-licensing:focus {
      background-color: #e58d50;
      border-color: #e58d50;
      color: #ffffff;
   }

   #nextool-config-form .text-licensing {
      color: #7c3aed !important;
   }

   #nextool-config-form .text-licensing-hero {
      color: #facc15 !important;
   }

   #nextool-config-form .border-licensing {
      border-color: #7c3aed !important;
   }

   #nextool-config-form .badge-licensing {
      background-color: #7c3aed;
      color: #ffffff;
   }

   #nextool-config-form .badge-dev {
      background-color: #0ea5e9;
      color: #ffffff;
   }

   #nextool-config-form .nextool-border-free {
      border-color: #0d9488 !important;
      border-width: 2px !important;
   }

   #nextool-config-form .nextool-border-paid {
      border: 2px solid transparent !important;
      background: linear-gradient(#fff, #fff) padding-box, linear-gradient(135deg, #4c1d95 0%, #7c3aed 40%, #14b8a6 100%) border-box !important;
      border-radius: 0.375rem !important;
   }

   #nextool-config-form .nextool-border-dev {
      border-color: #0ea5e9 !important;
      border-width: 2px !important;
   }

   #nextool-config-form .nextool-color-free { color: #0d9488 !important; }
   #nextool-config-form .nextool-color-paid {
      background: linear-gradient(135deg, #4c1d95 0%, #7c3aed 40%, #14b8a6 100%) !important;
      -webkit-background-clip: text !important;
      -webkit-text-fill-color: transparent !important;
      background-clip: text !important;
   }
   #nextool-config-form .nextool-color-dev  { color: #0ea5e9 !important; }

   #nextool-config-form .nextool-badge-ribbon {
      position: absolute;
      top: -2px;
      right: -2px;
      padding: 3px 12px;
      font-size: 0.7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      border-radius: 0 0.375rem 0 0.375rem;
      color: #fff;
      z-index: 1;
   }
   #nextool-config-form .nextool-ribbon-free { background-color: #0d9488; }
   #nextool-config-form .nextool-ribbon-paid { background: linear-gradient(135deg, #4c1d95 0%, #7c3aed 40%, #14b8a6 100%); }
   #nextool-config-form .nextool-ribbon-dev  { background-color: #0ea5e9; }

   #nextool-config-form .nextool-price {
      font-size: 0.85rem;
      font-weight: 600;
   }

   #nextool-config-form .nextool-features li {
      margin-bottom: 2px;
      line-height: 1.4;
   }

   #nextool-config-form .bg-success-lt {
      background-color: rgba(13, 148, 136, 0.1);
   }

   #nextool-config-form .nextool-chip-category {
      border: 1px solid #d97706;
      color: #fff;
      background-color: #d97706;
   }
   #nextool-config-form .nextool-chip-category:hover {
      background-color: #b45309;
      border-color: #b45309;
      color: #fff;
   }
   #nextool-config-form .nextool-chip-category.active {
      background-color: #92400e;
      border-color: #92400e;
      color: #fff;
      box-shadow: 0 0 0 0.2rem rgba(217, 119, 6, 0.35);
   }
   #nextool-config-form .nextool-chip-category-badge {
      background-color: rgba(255, 255, 255, 0.25);
      color: #fff;
   }

   #nextool-config-form .nextool-screenshot-tooltip {
      max-width: 320px;
   }
   #nextool-config-form .nextool-screenshot-tooltip img {
      max-width: 100%;
      border-radius: 4px;
   }

   #nextool-config-form .btn-hero-validate {
      background-color: #facc15;
      border-color: #facc15;
      color: #111827;
   }

   #nextool-config-form .btn-hero-validate:hover,
   #nextool-config-form .btn-hero-validate:focus {
      background-color: #fef9c3;
      border-color: #fef9c3;
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
   #nextool-config-form .nextool-filter-chip:focus:not(.active),
   #nextool-config-form .nextool-filter-chip:focus-visible:not(.active) {
      outline: none;
      box-shadow: 0 1px 3px rgba(0,0,0,0.2);
   }
   #nextool-config-form .nextool-filter-chip .badge {
      font-size: 0.7rem;
      min-width: 1.2rem;
      background: rgba(255,255,255,0.25) !important;
      color: #fff !important;
   }

   /* Chips preenchidos por padrão */
   #nextool-config-form .nextool-filter-chip.btn-outline-success {
      background-color: #198754;
      border-color: #198754;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-primary {
      background-color: #0d6efd;
      border-color: #0d6efd;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-warning {
      background-color: #ffc107;
      border-color: #ffc107;
      color: #fff !important;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-warning .badge {
      background: rgba(0,0,0,0.15) !important;
      color: #fff !important;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-secondary {
      background-color: #6c757d;
      border-color: #6c757d;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-info {
      background-color: #0dcaf0;
      border-color: #0dcaf0;
      color: #fff !important;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-info .badge {
      background: rgba(0,0,0,0.15) !important;
      color: #fff !important;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-teal,
   #nextool-config-form .btn-outline-teal {
      background-color: #0d9488;
      border-color: #0d9488;
      color: #fff !important;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-licensing {
      background: linear-gradient(135deg, #4c1d95 0%, #7c3aed 40%, #14b8a6 100%);
      border-color: #7c3aed;
      color: #fff !important;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-dev {
      background-color: #0ea5e9;
      border-color: #0ea5e9;
      color: #fff !important;
   }

   /* Estado ativo — ring para indicar seleção */
   #nextool-config-form .nextool-filter-chip.active {
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px currentColor;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-success.active {
      background-color: #146c43;
      border-color: #146c43;
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px #198754;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-primary.active {
      background-color: #0b5ed7;
      border-color: #0b5ed7;
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px #0d6efd;
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
      background: linear-gradient(135deg, #3b1578 0%, #6d28d9 40%, #0d9488 100%);
      border-color: #6d28d9;
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px #7c3aed;
   }
   #nextool-config-form .nextool-filter-chip.btn-outline-dev.active {
      background-color: #0284c7;
      border-color: #0284c7;
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px #0ea5e9;
   }
   #nextool-config-form .nextool-filter-chip.nextool-chip-category.active {
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px #d97706;
   }

   #nextool-config-form .bg-teal {
      background-color: #0d9488 !important;
   }
   #nextool-config-form .bg-licensing {
      background: linear-gradient(135deg, #4c1d95, #7c3aed, #14b8a6) !important;
   }
   #nextool-config-form .bg-dev {
      background-color: #0ea5e9 !important;
   }
   #nextool-config-form #nextool-module-no-results {
      font-size: 0.95rem;
   }
   #nextool-config-form #nextool-module-search:focus {
      box-shadow: none;
      border-color: #ced4da;
   }
</style>
