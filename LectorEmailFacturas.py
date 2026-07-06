import imaplib
import email
from email.header import decode_header
from email.utils import parseaddr
import re
import json
from datetime import datetime, timedelta, timezone

# ==========================================
#      CONFIGURACIÓN DE MÚLTIPLES CUENTAS
# ==========================================
CUENTAS_GMAIL = [
    {
        "usuario": "drinksdepotsede1@gmail.com",
        "clave": "xksdbdnwzvpgmtea"
    },
    {
        "usuario": "distribuidoracentralbnma@gmail.com",
        "clave": "dejvrdkxwyhfcnvi",
    }
]

# LISTA DE REMITENTES PERMITIDOS
REMITENTES_VALIDOS = [
    "facturactscolombia@cen.biz",
    "facturaelectronica@ramo.com.co"
]

# Configurar la zona horaria de Bogotá (UTC -5) de forma nativa y universal
TZ_BOGOTA = timezone(timedelta(hours=-5))

lista_facturas = []

for cuenta in CUENTAS_GMAIL:
    GMAIL_USER = cuenta["usuario"]
    GMAIL_PASS = cuenta["clave"]

    try:
        mail = imaplib.IMAP4_SSL("imap.gmail.com", 993)
        mail.login(GMAIL_USER, GMAIL_PASS)
        mail.select("INBOX")

        fecha_hoy = datetime.now(TZ_BOGOTA).strftime("%d-%b-%Y")

        # Búsqueda IMAP de los remitentes válidos hoy
        criterio_busqueda = (
            f'(OR FROM "{REMITENTES_VALIDOS[0]}" FROM "{REMITENTES_VALIDOS[1]}") ON {fecha_hoy}'
        )
        
        status, messages = mail.uid('search', None, criterio_busqueda)
        mail_ids = messages[0].split()

        if mail_ids:
            for mail_id in mail_ids:
                uid_correo = mail_id.decode('utf-8')

                status, data = mail.uid('fetch', mail_id, '(RFC822)')
                raw_email = data[0][1]
                msg = email.message_from_bytes(raw_email)

                remitente_header = msg.get("From", "")
                _, remitente_correo = parseaddr(remitente_header)
                remitente_correo_limpio = remitente_correo.lower().strip()

                if remitente_correo_limpio not in REMITENTES_VALIDOS:
                    continue

                subject, encoding = decode_header(msg["Subject"])[0]
                if isinstance(subject, bytes):
                    subject = subject.decode(encoding if encoding else 'utf-8', errors='ignore')

                date_str = msg["Date"]
                try:
                    fecha_parsed = email.utils.parsedate_to_datetime(date_str)
                    fecha_bogota = fecha_parsed.astimezone(TZ_BOGOTA)
                    fecha_correo = fecha_bogota.strftime('%Y-%m-%d %H:%M:%S')
                except:
                    fecha_correo = datetime.now(TZ_BOGOTA).strftime('%Y-%m-%d %H:%M:%S')

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

                # Limpieza de HTML conservando los espacios clave
                texto_plano = re.sub('<[^<]+?>', ' ', body)
                texto_plano_limpio = " ".join(texto_plano.split())

                # =========================================================================
                #  EXTRACCIÓN DE DATOS COMPATIBLE CON AMBOS FORMATOS (TABLA Y CORRIDO)
                # =========================================================================
                
                # 1. PROVEEDOR
                proveedor = "No detectado"
                # Formato Tabla
                match_prov = re.search(r'Proveedor\s*:\s*([\s\S]*?)(?:N[uú]mero|Tipo|Fecha|$)', texto_plano_limpio, re.IGNORECASE)
                if match_prov:
                    proveedor = " ".join(match_prov.group(1).strip().split())
                else:
                    # Formato Corrido (Ramo)
                    match_prov_corrido = re.search(r'([\w\s]+?)\s+identificado\s+con\s+Nit', texto_plano_limpio, re.IGNORECASE)
                    if match_prov_corrido:
                        proveedor = match_prov_corrido.group(1).strip()

                # 2. NÚMERO DE DOCUMENTO
                num_documento = "No detectado"
                # Formato Tabla
                match_doc = re.search(r'N[uú]mero\s+de\s+documento\s*:\s*([A-Z0-9_-]+)', texto_plano_limpio, re.IGNORECASE)
                if match_doc:
                    num_documento = match_doc.group(1).strip()
                else:
                    # Formato Corrido (Ramo)
                    match_doc_corrido = re.search(r'n[uú]mero\s+([A-Z0-9_-]+)\s+mediante', texto_plano_limpio, re.IGNORECASE)
                    if match_doc_corrido:
                        num_documento = match_doc_corrido.group(1).strip()

                # 3. TIPO DE DOCUMENTO
                tipo_documento = "Factura de Venta"
                match_tipo = re.search(r'Tipo\s+de\s+documento\s*:\s*([\s\S]*?)(?:Fecha|Valor|$)', texto_plano_limpio, re.IGNORECASE)
                if match_tipo:
                    tipo_documento = " ".join(match_tipo.group(1).strip().split())
                elif "FACTURA ELECTRONICA DE VENTA" in texto_plano_limpio.upper():
                    tipo_documento = "Factura Electrónica de Venta"

                # 4. FECHA DE EMISIÓN
                fecha_emision = "No detectada"
                match_emision = re.search(r'Fecha\s+de\s+emisi[oó]n\s*:\s*(\d{4}-\d{2}-\d{2})', texto_plano_limpio, re.IGNORECASE)
                if match_emision:
                    fecha_emision = match_emision.group(1).strip()
                else:
                    # Formato Corrido (Ramo)
                    match_emision_corrido = re.search(r'fecha\s+de\s+emisi[oó]n\s+(\d{4}-\d{2}-\d{2})', texto_plano_limpio, re.IGNORECASE)
                    if match_emision_corrido:
                        fecha_emision = match_emision_corrido.group(1).strip()

                # 5. VALOR DE LA FACTURA
                valor = 0.00
                match_valor = re.search(r'Valor\s*:\s*\$\s*([\d.,]+)', texto_plano_limpio, re.IGNORECASE)
                if not match_valor:
                    match_valor = re.search(r'valor\s+total\s+de\s+([\d.,]+)', texto_plano_limpio, re.IGNORECASE)
                
                if match_valor:
                    valor_sucio = match_valor.group(1)
                    valor_limpiecita = re.sub(r'[^\d]', '', valor_sucio)
                    if valor_limpiecita:
                        try:
                            if "." in valor_sucio and len(valor_sucio.split(".")[-1]) == 2:
                                valor = float(valor_limpiecita) / 100
                            else:
                                valor = float(valor_limpiecita)
                        except:
                            valor = 0.00

                # ==========================================
                #        CONTROL DE DUPLICADOS Y AGREGADO
                # ==========================================
                id_unico_factura = f"{num_documento}_{int(valor)}"
                ya_existe = any(f['id_unico'] == id_unico_factura for f in lista_facturas)

                if not ya_existe and valor > 0:
                    lista_facturas.append({
                        'id_unico': id_unico_factura,
                        'uid_correo': uid_correo,
                        'cuenta_receptora': GMAIL_USER,
                        'remitente_correo': remitente_correo_limpio,
                        'proveedor': proveedor,
                        'numero_documento': num_documento,
                        'tipo_documento': tipo_documento,
                        'fecha_emision': fecha_emision,
                        'valor': valor,
                        'fecha_recepcion_correo': fecha_correo,
                        'asunto': subject
                    })

        mail.close()
        mail.logout()

    except Exception as e:
        lista_facturas.append({
            "error": f"Error en la cuenta {GMAIL_USER}: {str(e)}"
        })

print(json.dumps(lista_facturas))