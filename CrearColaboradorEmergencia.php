<?php
require_once 'Conexion.php';

class ColaboradorEmergencia {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // CREAR
    public function crear($colaborador_id, $nombre_contacto, $parentesco, $celular_1, $celular_2, $es_principal) {
        $sql = "INSERT INTO colaborador_emergencia (colaborador_id, nombre_contacto, parentesco, celular_1, celular_2, es_principal) 
                VALUES (:colaborador_id, :nombre_contacto, :parentesco, :celular_1, :celular_2, :es_principal)";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':colaborador_id' => $colaborador_id,
            ':nombre_contacto' => $nombre_contacto,
            ':parentesco' => $parentesco,
            ':celular_1' => $celular_1,
            ':celular_2' => $celular_2,
            ':es_principal' => $es_principal
        ]);
    }

    // LEER (Por ID de colaborador)
    public function obtenerPorColaborador($colaborador_id) {
        $sql = "SELECT * FROM colaborador_emergencia WHERE colaborador_id = :colaborador_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':colaborador_id' => $colaborador_id]);
        return $stmt->fetchAll();
    }

    // ACTUALIZAR
    public function actualizar($id, $nombre_contacto, $parentesco, $celular_1, $celular_2, $es_principal) {
        $sql = "UPDATE colaborador_emergencia 
                SET nombre_contacto = :nombre_contacto, 
                    parentesco = :parentesco, 
                    celular_1 = :celular_1, 
                    celular_2 = :celular_2, 
                    es_principal = :es_principal 
                WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':id' => $id,
            ':nombre_contacto' => $nombre_contacto,
            ':parentesco' => $parentesco,
            ':celular_1' => $celular_1,
            ':celular_2' => $celular_2,
            ':es_principal' => $es_principal
        ]);
    }

    // ELIMINAR
    public function eliminar($id) {
        $sql = "DELETE FROM colaborador_emergencia WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }
}
?>