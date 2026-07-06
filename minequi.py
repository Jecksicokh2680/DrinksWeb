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

    # Obtener la fecha de hoy en Bogotá en formato IMAP
    fecha_hoy = datetime.now(TZ_BOGOTA).strftime("%d-%b-%Y")

    # Buscar correos del día con los asuntos clave
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

            # Fecha interna del servidor de correo
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

            # Limpieza estándar para procesamiento de texto
            texto_plano = re.sub('<[^<]+?>', ' ', body)
            texto_plano_limpio = " ".join(texto_plano.split())

            # ==========================================
            #      EXTRACCIÓN DE TODOS LOS DATOS
            # ==========================================
            
            # 1. MONTO
            monto = 0.00
            match_monto = re.search(r'Monto\s*:\s*\$\s*([\d.,]+)', texto_plano_limpio, re.IGNORECASE) # Formato Tabla
            if not match_monto:
                match_monto = re.search(r'Recibiste\s+([\d.,]+)', texto_plano_limpio, re.IGNORECASE) # Formato Texto
            if not match_monto:
                match_monto = re.search(r'\$[\s\d.,]+', texto_plano_limpio)
                
            if match_monto:
                monto_sucio = match_monto.group(1 if len(match_monto.groups()) > 0 else 0)
                monto_limpio = re.sub(r'[^\d]', '', monto_sucio)
                if monto_limpio:
                    monto = float(monto_limpio)

            # 2. PAGADOR / REMITENTE
            pagador = "No detectado"
            match_pagador = re.search(r'Pagador\s*:\s*([\s\S]*?)(?:Banco|Referencia|$)', texto_plano_limpio, re.IGNORECASE) # Formato Tabla
            if not match_pagador:
                match_pagador = re.search(r'\bde\b\s+([\s\S]*?)\s+\bel\b', texto_plano_limpio, re.IGNORECASE) # Formato Texto
                
            if match_pagador:
                pagador = " ".join(match_pagador.group(1).strip().split())
                if len(pagador) > 40:
                    pagador = pagador[:40]

            # 3. NÚMERO DE TRANSACCIÓN LARGO Y CELULAR
            num_transaccion = "No detectado"
            celular = "No detectado"
            
            match_transaccion = re.search(r'N[uú]mero\s+de\s+transacci[oó]n\s*:\s*([A-Z0-9]{10,})', texto_plano_limpio, re.IGNORECASE)
            if match_transaccion:
                num_transaccion = match_transaccion.group(1).strip()
                # Extraer celular si termina con el patrón de 10 dígitos que inicia en 3
                if len(num_transaccion) >= 10 and num_transaccion[-10].startswith('3'):
                    celular = num_transaccion[-10:]
            
            # Si no vino en la transacción larga, buscamos un celular común de 10 dígitos suelto
            if celular == "No detectado":
                match_cel_comun = re.search(r'\b3\d{9}\b', texto_plano_limpio)
                if match_cel_comun:
                    celular = match_cel_comun.group(0)

            # 4. BANCO ORIGEN
            banco = "Nequi"  # Por defecto
            match_banco = re.search(r'Banco\s*:\s*([\w\s]+?)(?:Referencia|N[uú]mero|$)', texto_plano_limpio, re.IGNORECASE) # Formato Tabla
            if not match_banco:
                match_banco = re.search(r'desde\s+el\s+banco\s+([\w\s]+?)\.', texto_plano_limpio, re.IGNORECASE) # Formato Texto
            if match_banco:
                banco = match_banco.group(1).strip()

            # 5. REFERENCIA CORTA
            referencia = "No detectado"
            match_ref = re.search(r'Referencia\s*:\s*([A-Z0-9]+)', texto_plano_limpio, re.IGNORECASE)
            if match_ref:
                referencia = match_ref.group(1).strip()

            # ==========================================
            #         CONTROL DE DUPLICADOS
            # ==========================================
            id_transferencia = f"{monto}_{tiempo_llave}"
            ya_existe = any(t['id_unico'] == id_transferencia for t in lista_transferencias)

            if not ya_existe and monto > 0:
                lista_transferencias.append({
                    'id_unico': id_transferencia,
                    'uid_correo': uid_correo,
                    'monto': monto,
                    'celular': celular,
                    'pagador': pagador,
                    'banco_origen': banco,
                    'referencia': referencia,
                    'numero_transaccion_largo': num_transaccion,
                    'fecha_correo': fecha_correo,
                    'asunto': subject
                })

    mail.close()
    mail.logout()

except Exception as e:
    lista_transferencias = [{"error": str(e)}]

# Retornar el objeto JSON mapeado para PHP
print(json.dumps(lista_transferencias))