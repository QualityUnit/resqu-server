terraform {
  backend "s3" {
    key            = "apps/resqu/dev/terraform.tfstate"
    encrypt        = true
    bucket         = "qla-shared-terraform-state"
    dynamodb_table = "qla-shared-terraform-state-lock"
    region         = "eu-central-1"
    profile        = "shared"
  }
}

provider "aws" {
  region  = "eu-central-1"
  profile = "shared"
}

provider "aws" {
  region  = "us-east-1"
  profile = "shared"
  alias   = "shared-ue1"
}