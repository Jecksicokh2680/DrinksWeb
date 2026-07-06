import imaplib
import email
from email.header import decode_header
import re
import json
from datetime import datetime
from zoneinfo import ZoneInfo

GMAIL_USER = "drinksdepotsede1@gmail.com"
GMAIL_PASS = "xksdbdnwzvpgmtea"

# Configurar la zona horaria de Bogotá
TZ_BOGOTA = ZoneInfo("America/Bogota")

lista_transferencias = []

try:
    # Conectarse a Gmail usando IMAP estándar
    mail = imaplib.IMAP4_SSL("imap.gmail.com", 993)
    mail.login(GMAIL_USER, GMAIL_PASS)
    mail.select("INBOX")

    # Obtener la fecha de hoy en Bogotá en formato IMAP (ej: 06-Jul-2026)
    fecha_hoy = datetime.now(TZ_BOGOTA).strftime("%d-%b-%Y")

    # Buscamos ambos tipos de correo recibidos el día de hoy
    criterio_busqueda = f'(OR SUBJECT "Bre-B" SUBJECT "Detalle de tu venta" ON {fecha_hoy})'
    status, messages = mail.uid('search', None, criterio_busqueda)
    mail_ids = messages[0].split()

    if mail_ids:
        for mail_id in mail_ids:
            uid_correo = mail_id.decode('utf-8')

            # Descargar contenido del correo
            status, data = mail.uid('fetch', mail_id, '(RFC822)')
            raw_email = data[0][1]
            msg = email.message_from_bytes(raw_email)

            # Asunto
            subject, encoding = decode_header(msg["Subject"])[0]
            if isinstance(subject, bytes):
                subject = subject.decode(encoding if encoding else 'utf-8', errors='ignore')

            # Fecha interna del servidor de correo convertida a Bogotá
            date_str = msg["Date"]
            try:
                fecha_parsed = email.utils.parsedate_to_datetime(date_str)
                fecha_bogota = fecha_parsed.astimezone(TZ_BOGOTA)
                fecha_correo = fecha_bogota.strftime('%Y-%m-%d %H:%M:%S')
                tiempo_llave = fecha_bogota.strftime('%Y%m%d%H%M')
            except:
                fecha_correo = datetime.now(TZ_BOGOTA).strftime('%Y-%m-%d %H:%M:%S')
                tiempo_llave = datetime.now(TZ_BOGOTA).strftime('%Y%m%d%H%M')

            # Cuerpo del correo
            body = ""
            if msg.is_multipart():
                for part in msg.walk():
                    content_type = part.get_content_type()
                    if content_type in ["text/plain", "text/html"]:
                        payload = part.get_payload(decode=True)
                        if payload:
                            body += payload.decode('utf-8', errors='ignore') + "\n"
            else:
                payload = msg.get_payload(decode=True)
                if payload:
                    body = payload.decode('utf-8', errors='ignore')

            # -------------------------------------------------------------
            # NUEVO: EXTRAER EL CELULAR DIRECTAMENTE DESDE EL HTML EN BRUTO
            # -------------------------------------------------------------
            origen = "No detectado"
            
            # Buscamos la secuencia numérica oculta en todo el código fuente del correo antes de limpiarlo
            match_cadena_larga = re.search(r'\b\d{10,}[A-Z0-9]*3\d{9}\b', body)
            
            if match_cadena_larga:
                cadena_completa = match_cadena_larga.group(0)
                origen = cadena_completa[-10:]  # Tomamos los últimos 10 dígitos (el celular)
            else:
                # Si no está la cadena larga, limpiamos el texto y aplicamos los planes de respaldo
                texto_plano_temp = re.sub('<[^<]+?>', ' ', body)
                texto_plano_limpio_temp = " ".join(texto_plano_temp.split())
                
                # Respaldo 1: Buscar un número de celular normal (3XXXXXXXXX) suelto en el texto
                match_cel_tradicional = re.search(r'\b3\d{9}\b', texto_plano_limpio_temp)
                if match_cel_tradicional:
                    origen = match_cel_tradicional.group(0)
                else:
                    # Respaldo 2: Traer el nombre si definitivamente no hay ningún número celular
                    match_remitente = re.search(r'\bde\b\s+([\s\S]*?)\s+\bel\b', texto_plano_limpio_temp, re.IGNORECASE)
                    if match_remitente:
                        origen = " ".join(match_remitente.group(1).strip().split())
                        if len(origen) > 40:
                            origen = origen[:40]

            # Limpieza estándar para el Monto
            texto_plano = re.sub('<[^<]+?>', ' ', body)
            texto_plano_limpio = " ".join(texto_plano.split())

            # Extraer Monto
            monto = 0.00
            match_monto = re.search(r'Recibiste\s+([\d.,]+)', texto_plano_limpio, re.IGNORECASE)
            if not match_monto:
                match_monto = re.search(r'\$[\s\d.,]+', texto_plano_limpio)

            if match_monto:
                monto_sucio = match_monto.group(1 if len(match_monto.groups()) > 0 else 0)
                monto_limpio = re.sub(r'[^\d]', '', monto_sucio)
                if monto_limpio:
                    monto = float(monto_limpio)

            # --- CONTROL DE DUPLICADOS ---
            id_transferencia = f"{monto}_{tiempo_llave}"
            ya_existe = any(t['id_unico'] == id_transferencia for t in lista_transferencias)

            if not ya_existe and monto > 0:
                lista_transferencias.append({
                    'id_unico': id_transferencia,
                    'uid_correo': uid_correo,
                    'celular_o_remitente': origen,
                    'monto': monto,
                    'fecha_correo': fecha_correo,
                    'asunto': subject
                })

    mail.close()
    mail.logout()

except Exception as e:
    lista_transferencias = [{"error": str(e)}]

# Imprimir el resultado en JSON para que PHP lo capture
print(json.dumps(lista_transferencias))