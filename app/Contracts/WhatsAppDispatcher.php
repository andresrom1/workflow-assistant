<?php

namespace App\Contracts;

use App\Models\EmergencyContact;
use App\Models\User;

/**
 * Puerto de salida para los avisos de WhatsApp iniciados por el negocio
 * (fuera de la ventana de 24h → message templates aprobados por Meta).
 *
 * Recibe modelos de dominio y resuelve internamente el template + el formato
 * del número. El controller no sabe de templates ni de la Cloud API: solo pide
 * "avisá a este contacto / a este PAS". Implementaciones: CloudApi (envía vía
 * Job) y Log (no-op, solo loguea — default en local/testing).
 */
interface WhatsAppDispatcher
{
    /**
     * Avisa a un contacto de emergencia con la ubicación del usuario.
     *
     * @param  string  $userName  Nombre del usuario que dispara el aviso ({{1}})
     * @param  string  $locationUrl  Link de ubicación: Google Maps (Estado 1) o tracking_url (Estado 2) ({{2}})
     * @param  int  $estado  1 = "estoy bien" (ubicación estática) · 2 = "necesito que vengas" (tracking)
     */
    public function emergencyContactNotice(EmergencyContact $contact, string $userName, string $locationUrl, int $estado): void;

    /**
     * Avisa al PAS que su cliente reportó un siniestro, con el contacto para llamarlo.
     *
     * @param  string  $customerName  Nombre del cliente que reportó ({{1}})
     * @param  string  $customerContact  Teléfono/email del cliente para que el PAS lo llame ({{2}})
     */
    public function siniestroNoticeToPas(User $pas, string $customerName, string $customerContact): void;
}
