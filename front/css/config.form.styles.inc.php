<?php
/**
 * Nextools - Config Form Styles
 *
 * Estilos de compatibilidade e tema do formulário de configuração do Nextools
 * no GLPI 10 (layout, abas, cards de módulos).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */
?>
<style>
   .btn-outline-licensing {
      background-color: #b3541e;
      border-color: #b3541e;
      color: #ffffff;
   }

   .btn-outline-licensing:hover,
   .btn-outline-licensing:focus {
      background-color: #e58d50;
      border-color: #e58d50;
      color: #ffffff;
   }

   .text-licensing {
      color: #b3541e !important;
   }

   .text-licensing-hero {
      color: #FACC15 !important;
   }

   .border-licensing {
      border-color: #b3541e !important;
   }

   .badge-licensing {
      background-color: #b3541e;
      color: #ffffff;
   }

   .badge-dev {
      background-color: #0ea5e9;
      color: #ffffff;
   }

   .btn-hero-validate {
      background-color: #FACC15;
      border-color: #FACC15;
      color: #111827;
   }

   .btn-hero-validate:hover,
   .btn-hero-validate:focus {
      background-color: #FEF9C3;
      border-color: #FEF9C3;
      color: #111827;
   }

   .nextool-policy-actions {
      max-width: 480px;
   }

   .nextool-tab-card {
      margin-top: 1rem;
      color: #1f2937 !important;
   }

   .nextool-tab-card .card-body {
      color: #1f2937 !important;
   }

   .nextool-tab-card .text-muted {
      color: #6b7280 !important;
   }

   .nextool-tab-card .form-control,
   .nextool-tab-card .form-select,
   .nextool-tab-card input,
   .nextool-tab-card textarea {
      color: #1f2937 !important;
   }

   .nextool-tab-card .form-control[readonly] {
      -webkit-text-fill-color: #1f2937;
      opacity: 1;
   }

   .nextool-hero-actions {
      text-align: left;
      margin-top: 0.5rem;
   }

   @media (min-width: 992px) {
      .nextool-hero-actions {
         text-align: right;
      }
   }

   /* GLPI 10 legado usa #page .small { width: 1% }, causando textos verticais no hero */
   #page .nextool-config-table .small,
   .qtip .nextool-config-table .small,
   .modal .modal-body .nextool-config-table .small {
      width: auto;
   }
</style>
