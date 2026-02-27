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

   /* GLPI 10 legado usa #page .small { width: 1% }, causando textos verticais no hero */
   #page #nextool-config-form .small,
   .qtip #nextool-config-form .small,
   .modal .modal-body #nextool-config-form .small {
      width: auto;
   }
</style>
