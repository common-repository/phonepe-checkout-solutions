window.onload = () => {
    var panIndiaCodCharges = document.getElementById("woocommerce_phonepe_expressbuy_pan_india_cod_charges");
    var codDropdown = document.getElementById("woocommerce_phonepe_expressbuy_cod_config");
    var panIndiaCodChargesRow = panIndiaCodCharges.closest("tr");

    function handlePanIndiaCodChargesRowDisplay(){
        if(codDropdown.value == undefined || codDropdown.value == "default_wc_cod"){
            panIndiaCodChargesRow.style.display = 'none';
        }
        else{
            panIndiaCodChargesRow.style.display = '';
        }
    }

    handlePanIndiaCodChargesRowDisplay();
    codDropdown.addEventListener("change", () => handlePanIndiaCodChargesRowDisplay())
}