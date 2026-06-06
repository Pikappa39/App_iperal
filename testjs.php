<!DOCTYPE html>
<html>
    <body>
<input type="file" id="excelFile"></input>
<button type="submit" id="submit">Submit</button>
<button type="button" id="back">Indietro</button>
<input type="text" id="Nsettimana" placeholder="Numero settimana"></input>
<script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>

<script>
    const giorni=[
    "Lunedì",
    "Martedì",
    "Mercoledì",
    "Giovedì",
    "Venerdì",
    "Sabato",
    "Domenica"
    ];
    let orarioInserito = null; 
    let json = null;
document.getElementById("excelFile").addEventListener("change", (e) => {

    const file = e.target.files[0];
    const reader = new FileReader();

    reader.onload = (event) => {

        const data = new Uint8Array(event.target.result);

        const workbook = XLSX.read(data, {
            type: "array"
        });

        const sheetName = workbook.SheetNames[0];

        const sheet = workbook.Sheets[sheetName];

         json = XLSX.utils.sheet_to_json(sheet,{
            range:1,
            raw:false,
            defval:"RIPOSO"
        });
        console.log(json);

 };

    reader.readAsArrayBuffer(file);
});

document.getElementById("submit").addEventListener("click", (e) => {
    const payload={
        "settimana": document.getElementById("Nsettimana").value,
        "orari": json
    }
    fetch("connection_files/upload.php", {
    method: "POST",
    headers: {
        "Content-Type": "application/json"
    },
    body: JSON.stringify(payload)
})
.then(res => res.text())
.then(data => {
    console.log("Server:", data);
});
})
document.getElementById("back").addEventListener("click",(e)=>{
    window.location.href="index.php";
})
</script>
</body>
</html>